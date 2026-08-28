<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Exception\WorkflowStateRejected;
use Storm\Saga\Exception\WorkflowStateVersionMismatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drive every behind instance of one workflow type through its state migration chain: the SWEEP that
 * makes a lazy migration bounded and observable. It writes nothing itself; each row goes through the
 * engine's own migrate verb, the same fence, OCC update, migration chain and declared validator a
 * step uses, so there is exactly ONE write path whichever door a migration enters by.
 *
 * Without this sweep the lazy path still migrates every instance at its next wake; what the sweep
 * adds is the BOUND, a run that ends at zero, and the REPORT that says so, which is what lets the
 * old migrator hops be retired once no stored row needs them.
 *
 * A fence-busy row is skipped and counted, not an error: the live step holding that saga migrates it
 * lazily in the same transaction it was going to use anyway. A broken chain fails the run loudly and
 * stops: the migrator is code, and code that is wrong for one row is wrong for every row behind it.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:state:migrate maintenance_fee
 * ```
 */
#[AsCommand(
    name: 'storm:saga:state:migrate',
    description: 'Migrate every behind instance of a workflow type to its declared state version.',
)]
final class SagaStateMigrateCommand extends Command
{
    public function __construct(
        private readonly SagaOperator $engine,
        private readonly WorkflowRegistry $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'The workflow type whose instances to migrate');
    }

    /**
     * {@inheritDoc}
     *
     * @throws DbalException when the behind scan fails
     * @throws WorkflowStateVersionMismatch when a row is ahead of the code or its chain breaks
     * @throws WorkflowStateRejected when a migrated bag fails the declared validator
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = (string) $input->getArgument('type');

        if (! $this->registry->has($type)) {
            $io->error(sprintf('Unknown workflow type "%s". Registered types are listed by storm:saga:versions.', $type));

            return Command::INVALID;
        }

        $declared = $this->registry->get($type)->stateVersion;
        $behind = $this->behind($type, $declared);

        if ($behind === []) {
            $io->success(sprintf('Every "%s" instance already carries state_version %d — nothing to migrate.', $type, $declared));

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d "%s" instance(s) behind state_version %d.', count($behind), $type, $declared));

        $migrated = 0;
        $busy = 0;
        foreach ($behind as $correlationId) {
            $this->engine->migrateState($type, $correlationId) ? $migrated++ : $busy++;
        }

        $remaining = count($this->behind($type, $declared));

        $io->success(sprintf(
            '%d migrated, %d left to their live step (fence busy), %d behind remain.',
            $migrated, $busy, $remaining,
        ));

        return Command::SUCCESS;
    }

    /**
     * The behind population: RUNNING rows only, since a terminal row runs no activity again and the
     * retention prune is its exit; the count before and after is the sweep's honesty.
     *
     * @return list<string>
     *
     * @throws DbalException
     */
    private function behind(string $type, int $declared): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            /** @lang PostgreSQL */
            "SELECT correlation_id FROM workflow_instances
             WHERE workflow_type = :type AND status = 'running' AND state_version < :declared
             ORDER BY updated_at",
            ['type' => $type, 'declared' => $declared],
        );
    }
}
