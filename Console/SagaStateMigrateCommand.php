<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Override;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Exception\WorkflowStateRejected;
use Storm\Saga\Exception\WorkflowStateVersionMismatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drive every behind instance of one workflow type through its state migration chain: the SWEEP that
 * makes a lazy migration bounded and observable. It writes nothing itself; each row goes through the
 * engine's own migrate verb, the same fence, OCC update, migration chain and declared validator a
 * step uses, so there is exactly ONE write path whichever door a migration enters by.
 *
 * The scan uses bounded pages in correlation-id order and attempts at most the initial behind
 * count. Busy or already-current instances are skipped. Concurrent arrivals can require another
 * invocation; the final count is an observation, not a snapshot or a guarantee of zero.
 * A broken migration chain escapes and stops the run.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:state:migrate maintenance_fee
 * ```
 *
 * ```bash
 * bin/console storm:saga:state:migrate maintenance_fee --batch-size=50
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
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Maximum candidates loaded per page, from 1 to 1000', '100');
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

        $batchSize = $input->getOption('batch-size');
        if (! is_string($batchSize) || ! preg_match('/\A[0-9]+\z/', $batchSize) || (int) $batchSize < 1 || (int) $batchSize > 1000) {
            $io->error('The batch-size option must be a decimal integer from 1 to 1000.');

            return Command::INVALID;
        }

        $declared = $this->registry->get($type)->stateVersion;
        $behind = $this->countBehind($type, $declared);

        if ($behind === 0) {
            $io->success(sprintf('Every "%s" instance already carries state_version %d — nothing to migrate.', $type, $declared));

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d "%s" instance(s) behind state_version %d.', $behind, $type, $declared));

        $migrated = 0;
        $skipped = 0;
        $attempted = 0;
        $after = null;
        while ($attempted < $behind) {
            $page = $this->behind($type, $declared, $after, min((int) $batchSize, $behind - $attempted));
            if ($page === []) {
                break;
            }

            foreach ($page as $correlationId) {
                $this->engine->migrateState($type, $correlationId) ? $migrated++ : $skipped++;
                $after = $correlationId;
                $attempted++;
            }
        }

        $remaining = $this->countBehind($type, $declared);
        if ($attempted === $behind && $remaining > 0) {
            $io->text('Candidate budget reached; remaining instances may require another invocation.');
        }

        $io->success(sprintf(
            '%d migrated, %d skipped (busy or already current), %d behind remain.',
            $migrated, $skipped, $remaining,
        ));

        return Command::SUCCESS;
    }

    private function countBehind(string $type, int $declared): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM workflow_instances
             WHERE workflow_type = :type AND status = 'running' AND state_version < :declared",
            ['type' => $type, 'declared' => $declared],
        );
    }

    /**
     * @return list<string>
     */
    private function behind(string $type, int $declared, ?string $after, int $limit): array
    {
        $cursor = $after === null ? '' : ' AND correlation_id > :after';
        $parameters = ['type' => $type, 'declared' => $declared, 'limit' => $limit];
        if ($after !== null) {
            $parameters['after'] = $after;
        }

        return $this->connection->fetchFirstColumn(
            "SELECT correlation_id FROM workflow_instances
             WHERE workflow_type = :type AND status = 'running' AND state_version < :declared".$cursor.'
             ORDER BY correlation_id LIMIT :limit',
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );
    }
}
