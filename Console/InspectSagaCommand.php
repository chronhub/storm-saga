<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Store\Inspection\OutboxSnapshot;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\Inspection\TimerSnapshot;
use Storm\Saga\Workflow\CompensationRecord;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Read-only introspection of a saga by correlation id: its instances with state, status and retries,
 * plus the armed timers, the commands it has issued, and the compensation log, THE forensic data of a
 * halted saga. The three-table read schema lives in the {@see \Storm\Saga\Store\Inspection\SagaInspectionGateway}, which serves one
 * consistent REPEATABLE READ snapshot; this command is pure presentation.
 *
 * The type argument is optional. A correlation id is normally unique to one saga, so passing the
 * correlation alone shows that saga and also surfaces the rare cross-saga case of several workflow types
 * sharing one correlation; passing the type narrows to one. The gateway reads straight from the tables
 * precisely so that cross-saga case stays visible rather than being silently collapsed.
 *
 * Scriptable: `--json` prints the machine-readable snapshot with full class names and untruncated
 * errors, and "no saga" exits FAILURE; a mistyped correlation must fail a pipeline, not answer a quiet
 * success, the same family as the strict `--batch` parsing.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:inspect <correlation-id>
 * bin/console storm:saga:inspect <correlation-id> <workflow-type>
 * bin/console storm:saga:inspect <correlation-id> --json | jq '.[0].compensations'
 * ```
 */
#[AsCommand(
    name: 'storm:saga:inspect',
    description: 'Inspect a saga by correlation: instance state/status/retries + its timers + its outbox commands (read-only).',
)]
final class InspectSagaCommand extends Command
{
    private const int MAX_ERROR_LEN = 60;

    public function __construct(
        private readonly SagaInspectionGateway $gateway,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('correlation', InputArgument::REQUIRED, 'The saga correlation id');
        $this->addArgument('type', InputArgument::OPTIONAL, 'Narrow to one workflow type (default: every saga sharing the correlation)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable snapshot (full class names, untruncated errors)');
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException on a retries- or compensations-bag decode failure, or encoding the --json output
     * @throws Exception on a DBAL read failure
     * @throws Throwable rethrown from the gateway's read-only transaction wrapper
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $correlation = (string) $input->getArgument('correlation');
        $typeArg = $input->getArgument('type');
        $type = is_string($typeArg) && $typeArg !== '' ? $typeArg : null;
        $json = $input->getOption('json') === true;

        $sagas = $this->gateway->inspect($correlation, $type);

        if ($json) {
            $output->writeln(json_encode(array_map(static fn (SagaSnapshot $s): array => $s->toArray(), $sagas), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $sagas === [] ? Command::FAILURE : Command::SUCCESS;
        }

        if ($sagas === []) {
            $io->getErrorStyle()->warning(sprintf('No saga for correlation "%s"%s.', $correlation, $type !== null ? " (type \"$type\")" : ''));

            return Command::FAILURE; // scriptable: a mistyped correlation must not answer a quiet success
        }

        if (count($sagas) > 1) {
            $sagas
                |> count(...)
                |> (static fn ($x) => sprintf('%d sagas share this correlation (cross-saga).', $x))
                |> $io->writeln(...);
        }

        foreach ($sagas as $saga) {
            $this->renderSaga($io, $saga);
        }

        return Command::SUCCESS;
    }

    private function renderSaga(SymfonyStyle $io, SagaSnapshot $saga): void
    {
        $io->section($saga->workflowType);
        $io->writeln(sprintf(
            ' state=<info>%s</info>   status=<info>%s</info>   version=%d   started=%s',
            $saga->stateKey,
            $saga->status,
            $saga->version,
            $saga->startedAt ?? '—',
        ));
        // the second line answers the questions the first cannot: which shape is it running, which run
        // of this correlation is it, how much retry budget did it burn, and, the one an operator
        // reaches for first, how long has it been sitting there.
        $io->writeln(sprintf(
            ' definition=v%d   generation=%d   retries_total=%d%s   updated=%s%s',
            $saga->definitionVersion,
            $saga->generation,
            $saga->retryTotal,
            // shown only when a retime happened: "this deadline has been pushed N times" is incident
            // data, and a zero would read as noise on every ordinary saga
            $saga->retimes > 0 ? sprintf('   retimes=%d', $saga->retimes) : '',
            $saga->updatedAt ?? '—',
            $saga->waivedAt !== null ? sprintf('   <comment>waived=%s</comment>', $saga->waivedAt) : '',
        ));

        // A freeze holds the status at `running`, so the line above cannot show it and an operator
        // reads a stuck saga. Its own line, with the reason, because the next verb is usually cancel
        // and cancel passes THROUGH a freeze.
        if ($saga->pausedAt !== null) {
            $io->writeln(sprintf(
                ' <comment>paused=%s</comment>%s',
                $saga->pausedAt,
                $saga->pausedReason !== null ? sprintf('   reason=%s', $saga->pausedReason) : '',
            ));
        }

        // the type freeze is a second, wider hold with no stamp on this row: an instance can be
        // executable on its own and still never run, and lifting one does not lift the other
        if ($saga->typePaused) {
            $io->writeln(sprintf(' <comment>paused(type)=%s</comment>   lift with storm:saga:resume %s', $saga->workflowType, $saga->workflowType));
        }

        // A child reads as a root without this: the machine channel and the HTTP twin both carry the
        // ancestry, and the operator who reaches for `inspect` during an incident is the one who has
        // to decide whether to cancel HERE or one level up. Shown only when a parent exists, a root
        // saga having nothing to say.
        if ($saga->parentCorrelationId !== null) {
            $io->writeln(sprintf(
                ' parent=<info>%s/%s</info>   root=%s',
                $saga->parentWorkflowType ?? '—',
                $saga->parentCorrelationId,
                $saga->rootCorrelationId ?? '—',
            ));
        }

        if ($saga->retries !== []) {
            $io->newLine();
            $io->writeln(' <comment>retries</comment>');
            foreach ($saga->retries as $state => $visit) {
                // `since` answers the incident's time question, "how long has this state been
                // retrying", which the bare count cannot; a legacy row without a window shows the
                // count alone
                $io->writeln(sprintf(
                    '   %s ×%d%s',
                    $state,
                    $visit['n'],
                    $visit['since'] !== null ? sprintf(' since %s', $visit['since']) : '',
                ));
            }
        }

        $this->renderExposed($io, $saga->exposed);
        $this->renderCompensations($io, $saga->compensations);
        $this->renderTimers($io, $saga->timers);
        $this->renderOutbox($io, $saga->outbox);
    }

    /**
     * The one sanctioned window on business state, the `#[ExposesState]` subset of `vars` the gateway
     * filtered against the compiled allowlist. Every other channel serves it, `--json` and the HTTP
     * twin alike, and the operator reading this one during an incident is the one who needs it.
     *
     * Shown only when the workflow opened the window: closed is the default, and an empty section
     * would read as a saga with no state rather than a saga that declared none.
     *
     * @param  array<string, mixed>  $exposed
     */
    private function renderExposed(SymfonyStyle $io, array $exposed): void
    {
        if ($exposed === []) {
            return;
        }

        $io->newLine();
        $io->writeln(' <comment>exposed state</comment>');
        $io->table(
            ['key', 'value'],
            array_map(
                static fn (string $key, mixed $value): array => [$key, self::cell($value)],
                array_keys($exposed),
                array_values($exposed),
            ),
        );
    }

    /**
     * One cell for an app-authored value of any shape. The bag is `mixed` by contract, so a render
     * assuming scalars would fatal on the first workflow exposing a list; a shape that will not encode
     * degrades to its type name rather than blanking the row.
     */
    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => self::truncate((string) $value),
            default => self::truncate(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: get_debug_type($value)),
        };
    }

    /**
     * The rollback log, shown only when the saga has one: for a halted or compensated saga it is THE
     * forensic record of what was undone, what was skipped and why, in completion order.
     *
     * @param  list<CompensationRecord>  $compensations
     */
    private function renderCompensations(SymfonyStyle $io, array $compensations): void
    {
        if ($compensations === []) {
            return;
        }

        $io->newLine();
        $compensations
            |> count(...)
            |> (static fn ($x) => sprintf(' <comment>compensations</comment> (%d)', $x))
            |> $io->writeln(...);
        $io->table(
            ['step', 'status', 'confirmed', 'degraded', 'at', 'reason'],
            array_map(static fn (CompensationRecord $c): array => [
                $c->step,
                $c->status->value,
                $c->confirmed ? 'yes' : '—',
                $c->degraded ? 'yes' : '—',
                $c->at ?? '—',
                self::truncate($c->reason ?? ''),
            ], $compensations),
        );
    }

    /**
     * @param  list<TimerSnapshot>  $timers
     */
    private function renderTimers(SymfonyStyle $io, array $timers): void
    {
        $io->newLine();
        $timers
            |> count(...)
            |> (static fn ($x) => sprintf(' <comment>timers</comment> (%d)', $x))
            |> $io->writeln(...);
        if ($timers !== []) {
            $io->table(
                // the id leads because it is what a repair takes: storm:saga:unpark names a row by it
                ['id', 'kind', 'state', 'fire at', 'claimed at', 'attempts', 'parked'],
                array_map(static fn (TimerSnapshot $t): array => [
                    (string) $t->id,
                    $t->kind,
                    $t->stateKey,
                    $t->fireAt,
                    $t->claimedAt ?? '—',
                    $t->attempts === 0 ? '—' : (string) $t->attempts,
                    // a parked row is quarantined poison: show WHEN and WHY, the operator's first question
                    $t->parkedAt === null ? '—' : sprintf('%s — %s', $t->parkedAt, $t->lastError ?? '?'),
                ], $timers),
            );
        }
    }

    /**
     * @param  list<OutboxSnapshot>  $outbox
     */
    private function renderOutbox(SymfonyStyle $io, array $outbox): void
    {
        $outbox
            |> count(...)
            |> (static fn ($x) => sprintf(' <comment>outbox</comment> (%d)', $x))
            |> $io->writeln(...);
        if ($outbox !== []) {
            // `issued by` is the provenance pair the settle itself pairs on: which step emitted the
            // command and at which step marker. A human reading a dead-lettered row asks exactly that.
            $io->table(
                // `evidence` is shown only where it MEANS something: on a dead-letter, where it is the
                // difference between "the effect provably never landed" and "nobody proved anything"
                ['status', 'command', 'issued by', 'evidence', 'bus', 'attempts', 'created at', 'error'],
                array_map(static fn (OutboxSnapshot $o): array => [
                    $o->status,
                    self::shortClass($o->command ?? '?'),
                    sprintf('%s@v%d/g%d', $o->issuedFromState === '' ? '—' : $o->issuedFromState, $o->issuedAtVersion, $o->generation),
                    $o->status === OutboxStatus::Failed->value ? $o->evidence->value : '—',
                    $o->bus,
                    (string) $o->attempts,
                    $o->createdAt,
                    self::truncate($o->lastError ?? ''),
                ], $outbox),
            );
        }
    }

    private static function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) <= self::MAX_ERROR_LEN ? $value : mb_substr($value, 0, self::MAX_ERROR_LEN - 1).'…';
    }
}
