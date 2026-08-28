<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Exception;
use JsonException;
use Override;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\Inspection\ZombieChildSnapshot;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The zombie sweep, READ only: living children whose parent settled or vanished. The cascade is
 * the AUTHORITY that reaches a child when its parent aborts; this view is the backstop that makes
 * a missed cascade visible instead of silent, the same doctrine as the hold sweep: authority
 * cascades, the sweep watches. An empty listing is the healthy state; a row here is a cascade
 * command still in flight, dead-lettered, or lost, so inspect the child, then cancel it
 * deliberately.
 *
 * The rows come from TWO sources, never a birth that raced its parent's abort, which the shared
 * lock taken on the parent row at birth rules out: a cascade command that died in flight, and one
 * DELIVERED but deliberately refused. A non-forced abort never discards a child's in-flight effect,
 * invariant 2; the engine voices that refusal as `SagaCancelRefused` at cancel time, and the
 * surviving child lands in this listing once its parent settles.
 *
 * Acting stays deliberate and per-child, `storm:saga:cancel` with the child's correlation; this
 * command never cancels: a sweep that kills is an authority, and the authority is the cascade.
 *
 * Scriptable: `--json` prints the machine-readable rows, the same snapshot contract every renderer
 * serves. `--limit` bounds the read.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:children
 * bin/console storm:saga:children --json | jq '.[].correlation_id'
 * ```
 */
#[AsCommand(
    name: 'storm:saga:children',
    description: 'List living children of settled or vanished parents (the zombie sweep, read-only).',
)]
final class SagaChildrenCommand extends Command
{
    public function __construct(private readonly SagaInspectionGateway $gateway)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable rows');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to list', '100');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL read failure
     * @throws JsonException on encoding the --json output
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Reject a mistyped cap loud: `max(1, (int) '5O')` quietly listed five rows and called it the
        // population, on the one view whose every row commands a cancel.
        $limit = PositiveIntOption::parse($input->getOption('limit'));
        if ($limit === null) {
            $io->getErrorStyle()->error('--limit must be a positive integer (rows to list), e.g. --limit=100.');

            return Command::INVALID;
        }

        $listing = $this->gateway->zombieChildren($limit);
        $zombies = $listing->children;

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(
                $listing->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return Command::SUCCESS;
        }

        if ($zombies === []) {
            $io->success('No zombie children: every living child has a running parent.');

            return Command::SUCCESS;
        }

        $io->table(
            ['child workflow', 'child correlation', 'parent correlation', 'parent status', 'started at'],
            array_map(static fn (ZombieChildSnapshot $z): array => [
                $z->workflowType,
                $z->correlationId,
                $z->parentCorrelationId,
                $z->parentStatus ?? 'missing',
                $z->startedAt ?? '',
            ], $zombies),
        );
        $io->note(sprintf('%d zombie child(ren): the cascade should have reached these — inspect, then cancel deliberately.', count($zombies)));

        if ($listing->truncated) {
            $io->getErrorStyle()->warning(sprintf('Capped at %d rows — more zombies match. Work these, then re-run, or raise --limit (max %d).', $listing->limit, SagaInspectionGateway::MAX_LIMIT));
        }

        return Command::SUCCESS;
    }
}
