<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Clock\Duration;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\Inspection\SagaSummarySnapshot;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The sagas, filtered: the door for an operator who has an incident and no correlation id. Every
 * other read on this module starts from a correlation; this one produces them.
 *
 * The filters are the columns the store already indexes, nothing derived: type, lifecycle status,
 * how long a row has been untouched, and whether its retry budget was waived. `--idle-for` is the
 * one to reach for first; combined with `--status=running` it IS the stuck-saga question, and that
 * pairing is what rides the `(status, updated_at)` index, whose leading column is the status.
 *
 * Rows always come oldest-touched first, which is the operator's question and not a property of the
 * index: without a `--status`, the order costs a read of the population and a top-N sort. Narrowing
 * by status is what keeps a large park's listing cheap.
 *
 * Lineage is deliberately NOT a filter here: `storm:saga:children` finds the orphans, the living
 * children of settled or vanished parents, over the whole park. This lists a population by its own
 * attributes; that one answers one question about the graph, and neither takes a parent.
 *
 * A listing shows scalars only, never a saga's timers or outbox: those cost a query per row.
 * `storm:saga:inspect <correlation>` is the next hop, and the correlation column is what to feed it.
 *
 * Scriptable: `--json` prints the machine-readable listing, and an empty result exits FAILURE so a
 * filter that matched nothing fails a pipeline rather than answering a quiet success, the same
 * contract as `storm:saga:inspect`. The server-side cap is announced in both channels: a truncated
 * listing that looked complete would be the one lie an operator cannot recover from.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:list --status=running --idle-for=1h
 * bin/console storm:saga:list --type=transfer --limit=200
 * bin/console storm:saga:list --status=halted --json | jq -r '.sagas[].correlation_id'
 * ```
 */
#[AsCommand(
    name: 'storm:saga:list',
    description: 'List sagas by type, status, idle time or waived budget — the entry point when you have no correlation id (read-only).',
)]
final class ListSagasCommand extends Command
{
    public function __construct(
        private readonly SagaInspectionGateway $gateway,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Narrow to one workflow type');
        $this->addOption('status', null, InputOption::VALUE_REQUIRED, 'Narrow to one status: running, completed, halted, compensated — the one narrowing the oldest-first order is indexed on');
        $this->addOption('idle-for', null, InputOption::VALUE_REQUIRED, 'Only sagas untouched for at least this long (e.g. 30m, 6h, 3d; m is minutes)');
        $this->addOption('waived', null, InputOption::VALUE_NONE, 'Only sagas whose retry budget was waived');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to return (max '.SagaInspectionGateway::MAX_LIMIT.')', (string) SagaInspectionGateway::DEFAULT_LIMIT);
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable listing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException on encoding the --json output
     * @throws Exception on a DBAL read failure
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = $input->getOption('json') === true;

        $status = $this->parseStatus($input);

        if ($status === false) {
            $io->getErrorStyle()->error(sprintf('Unknown status "%s". Expected one of: %s.', (string) $input->getOption('status'), implode(', ', array_map(static fn (WorkflowStatus $s): string => $s->value, WorkflowStatus::cases()))));

            return Command::INVALID;
        }

        $idle = $this->parseIdleFor($input);

        if ($idle === false) {
            $io->getErrorStyle()->error(sprintf('Malformed --idle-for "%s". Expected a compact duration such as 30m, 6h or 3d.', (string) $input->getOption('idle-for')));

            return Command::INVALID;
        }

        $limit = PositiveIntOption::parse($input->getOption('limit'));

        if ($limit === null) {
            $io->getErrorStyle()->error('The --limit must be a positive integer.');

            return Command::INVALID;
        }

        $typeOption = $input->getOption('type');

        $listing = $this->gateway->list(
            type: is_string($typeOption) && $typeOption !== '' ? $typeOption : null,
            status: $status,
            idleForSeconds: $idle,
            waivedOnly: $input->getOption('waived') === true,
            limit: $limit,
        );

        if ($json) {
            $output->writeln(json_encode($listing->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $listing->sagas === [] ? Command::FAILURE : Command::SUCCESS;
        }

        if ($listing->sagas === []) {
            $io->getErrorStyle()->warning('No saga matches these filters.');

            return Command::FAILURE; // scriptable: a filter that matched nothing must not answer a quiet success
        }

        $io->table(
            ['type', 'correlation', 'state', 'status', 'updated', 'def', 'gen', 'retries', 'flags'],
            array_map(static fn (SagaSummarySnapshot $s): array => [
                $s->workflowType,
                $s->correlationId,
                $s->stateKey,
                $s->status,
                $s->updatedAt ?? '—',
                'v'.$s->definitionVersion,
                (string) $s->generation,
                $s->retryTotal === 0 ? '—' : (string) $s->retryTotal,
                self::flags($s),
            ], $listing->sagas),
        );

        $io->writeln(sprintf(' %d saga(s), oldest-touched first.', count($listing->sagas)));

        if ($listing->truncated) {
            // never let a capped listing read like a complete one
            $io->getErrorStyle()->warning(sprintf('Capped at %d rows — more sagas match. Narrow the filters or raise --limit (max %d).', $listing->limit, SagaInspectionGateway::MAX_LIMIT));
        }

        return Command::SUCCESS;
    }

    /**
     * The paused, waived and child markers, the three facts a listing row cannot show as a column
     * without spending width on a mostly-empty one.
     *
     * A freeze leaves the status at `running` by design, a paused saga being a living one that is not
     * executed, so a listing silent about it shows an instance that reads exactly like a stuck one.
     * The verb an operator reaches for next passes THROUGH the freeze and compensates.
     */
    private static function flags(SagaSummarySnapshot $saga): string
    {
        $flags = [];

        if ($saga->pausedAt !== null) {
            $flags[] = 'paused';
        }

        // the widest freeze of the surface, and the one no instance stamp can reveal: it gates births
        // as well as steps, so every row of that type is held even though none of them says so
        if ($saga->typePaused) {
            $flags[] = 'paused(type)';
        }

        if ($saga->waivedAt !== null) {
            $flags[] = 'waived';
        }

        if ($saga->parentCorrelationId !== null) {
            $flags[] = 'child';
        }

        return $flags === [] ? '—' : implode(' ', $flags);
    }

    /**
     * The parsed status, null when unfiltered, or false when the caller named one that does not
     * exist; a typo must fail loudly, never silently widen the listing to everything.
     */
    private function parseStatus(InputInterface $input): WorkflowStatus|false|null
    {
        $raw = $input->getOption('status');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return WorkflowStatus::tryFrom($raw) ?? false;
    }

    /**
     * The parsed idle window in seconds, null when unfiltered, or false on a malformed duration;
     * the same strictness as the maintenance commands' `--before`: a guard that cannot be parsed is
     * not a guard.
     */
    private function parseIdleFor(InputInterface $input): int|false|null
    {
        $raw = $input->getOption('idle-for');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $duration = Duration::fromString($raw);

        return $duration === null ? false : $duration->seconds;
    }
}
