<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Override;
use Storm\Saga\Schema\CircuitBreakerSchema;
use Storm\Saga\Schema\SagaSchemaCatalog;
use Storm\Saga\Schema\WorkflowCorrelationsSchema;
use Storm\Saga\Schema\WorkflowInstancesSchema;
use Storm\Saga\Schema\WorkflowOutboxArchiveSchema;
use Storm\Saga\Schema\WorkflowOutboxSchema;
use Storm\Saga\Schema\WorkflowPausesSchema;
use Storm\Saga\Schema\WorkflowTimersSchema;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Support\Dbal\InstallLock;
use Storm\Support\Dbal\SchemaProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Creates the saga tables `workflow_instances`, `workflow_correlations`, `workflow_timers`,
 * `workflow_outbox` and `circuit_breaker`. Separate from `storm:install` on purpose: Saga is opt-in,
 * nothing in the core depends on it, so its schema is opt-in too; an app that uses sagas runs this in
 * addition to `storm:install`.
 *
 * Verified and transactional, exactly like `storm:install`: the DDL and the conformance probe run in
 * ONE transaction under an advisory lock, so the command either proves the schema it just created or
 * rolls back untouched, and never reports success on a shape the runtime cannot use.
 *
 * Three behaviors, mutually exclusive flags, mirroring `storm:install`:
 * - No flag: create only, idempotent.
 * - `--drop`: drop only, no recreate.
 * - `--reset`: drop then recreate.
 *
 * Carries the same operational contract as `storm:install`:
 *
 * - The target `current_database()` / `current_schema()` / server version is printed before
 *   anything runs.
 *
 * - A server below the PostgreSQL 17 product floor is refused.
 *
 * - Every destructive flag demands an interactive confirmation naming the target, or `--force`
 *   when no interaction is possible.
 *
 * When sagas are still `running` the confirmation says how many: the cleanup refuses to touch a
 * running saga, so the drop must not destroy them silently by the back door, leaving their
 * in-flight effects NOT compensated, since the bookkeeping that could compensate them is exactly
 * what is being dropped. A drop also forgets every correlation ever claimed, which is why the
 * destructive flags belong to disposable environments: a rebuilt `workflow_correlations` lets a
 * correlation the system already spent start a saga again.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:install
 * bin/console storm:saga:install --drop --force
 * bin/console storm:saga:install --reset --force
 * ```
 */
#[AsCommand(
    name: 'storm:saga:install',
    description: 'Create the saga tables (workflow_instances/correlations/timers/outbox/circuit_breaker; --drop to drop only; --reset to drop + recreate).',
)]
final class InstallSagaCommand extends Command
{
    /** "STORMSAG" packed as an int64; the advisory lock serializing saga installs/resets per database. */
    private const int ADVISORY_LOCK_KEY = 0x53544F524D534147;

    /** The product's documented floor; the check mirrors `storm:install` so every installer refuses the same servers. */
    private const int MINIMUM_SERVER_VERSION_NUM = 170_000;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('drop', null, InputOption::VALUE_NONE, 'Drop the tables (no re-create). Mutually exclusive with --reset.');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Reset: drop the tables then re-create them. Mutually exclusive with --drop.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Confirm --drop/--reset without asking (required when the session is non-interactive).');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure creating / dropping the saga tables
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $drop = (bool) $input->getOption('drop');
        $reset = (bool) $input->getOption('reset');

        if ($drop && $reset) {
            $io->error('--drop and --reset are mutually exclusive. --drop drops only; --reset drops and re-creates.');

            return Command::INVALID;
        }

        [$database, $schema, $version, $versionNum] = $this->home();
        $io->text(sprintf('%s / %s — PostgreSQL %s', $database, $schema, $version));
        if ($versionNum < self::MINIMUM_SERVER_VERSION_NUM) {
            $io->error(sprintf('PostgreSQL 17 is the documented floor — this connection runs %s.', $version));

            return Command::FAILURE;
        }

        if ($drop || $reset) {
            $target = sprintf('%s/%s', $database, $schema);
            if (! (bool) $input->getOption('force')) {
                if (! $input->isInteractive()) {
                    $io->error(sprintf(
                        '--%s destroys the saga bookkeeping on %s and the session is non-interactive: pass --force to confirm.',
                        $drop ? 'drop' : 'reset',
                        $target,
                    ));

                    return Command::INVALID;
                }

                $running = $this->runningCount();
                if ($running > 0) {
                    $io->warning(sprintf('%d saga(s) are RUNNING — dropping the tables destroys them, their in-flight effects uncompensated.', $running));
                }
                if (! $io->confirm(sprintf('%s the saga tables on %s?', $drop ? 'Drop' : 'Drop and re-create', $target), false)) {
                    $io->warning('Aborted — nothing was dropped.');

                    return Command::SUCCESS;
                }
            }
        }

        $problems = $this->applyVerified(
            $drop || $reset ? $this->down() : [],
            $drop ? [] : $this->up(),
        );

        if ($problems !== []) {
            $io->error('Saga schema NOT installed — the live schema diverges from what the package declares:');
            $io->listing($problems);
            $io->writeln('Nothing was changed: the whole install rolled back. Fix the pre-existing objects, or use --reset on a disposable environment.');

            return Command::FAILURE;
        }

        $io->success(match (true) {
            $drop => 'Saga schema dropped.',
            $reset => 'Saga schema reset and verified.',
            default => 'Saga schema installed and verified.',
        });

        return Command::SUCCESS;
    }

    /**
     * The whole install: ONE transaction under an advisory lock, with the conformance probe before the
     * commit. `CREATE … IF NOT EXISTS` reads "an object of this name exists" as "the object is the one
     * declared", so a pre-existing `workflow_correlations` missing its partial reject index would
     * no-op the DDL and the install would report success on a schema whose reuse refusal is GONE.
     * Either the schema is proven or the transaction rolls back untouched.
     *
     * The lock serializes concurrent installs per database, so two deployers cannot interleave DDL and
     * have one of them verify the other's half-built schema.
     *
     * @param  list<string>  $down  statements to run first, in drop / reset modes
     * @param  list<string>  $up  statements to run next, in install / reset modes
     * @return list<string> conformance problems if the schema diverged and the transaction rolled back; empty on success
     *
     * @throws Exception on a DBAL failure, after rolling back
     */
    private function applyVerified(array $down, array $up): array
    {
        $this->connection->beginTransaction();

        try {
            InstallLock::acquire($this->connection, self::ADVISORY_LOCK_KEY, 'storm:saga:install');

            foreach ([...$down, ...$up] as $statement) {
                $this->connection->executeStatement($statement);
            }

            if ($up !== []) {
                $problems = new SchemaProbe()->problems(
                    $this->connection,
                    SagaSchemaCatalog::catalog(),
                    array_keys(SagaSchemaCatalog::COLUMNS),
                );
                if ($problems !== []) {
                    $this->connection->rollBack();

                    return $problems;
                }
            }

            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return [];
    }

    /**
     * Where this connection's DDL lands. The schema is `search_path`-relative by design, so the
     * target is printed, never assumed.
     *
     * @return array{string, string, string, int} database, schema, server version, numeric version
     *
     * @throws Exception on a DBAL failure probing the connection
     */
    private function home(): array
    {
        /** @var array{db: string, schema: string, version: string, version_num: int|string} $row */
        $row = $this->connection->fetchAssociative(
            /** @lang PostgreSQL */
            "SELECT current_database() AS db, current_schema() AS schema,
                    current_setting('server_version') AS version,
                    current_setting('server_version_num')::int AS version_num",
        );

        return [$row['db'], $row['schema'], $row['version'], (int) $row['version_num']];
    }

    /**
     * The running count, defensively zero when the table does not exist yet; nothing to protect.
     */
    private function runningCount(): int
    {
        try {
            return (int) $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT count(*) FROM workflow_instances WHERE status = :status',
                ['status' => WorkflowStatus::Running->value],
            );
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function up(): array
    {
        return [
            ...WorkflowInstancesSchema::up(),
            ...WorkflowCorrelationsSchema::up(),
            ...WorkflowTimersSchema::up(),
            ...WorkflowOutboxSchema::up(),
            ...WorkflowOutboxArchiveSchema::up(),
            ...WorkflowPausesSchema::up(),
            ...CircuitBreakerSchema::up(),
        ];
    }

    /**
     * @return list<string>
     */
    private function down(): array
    {
        return [
            ...CircuitBreakerSchema::down(),
            ...WorkflowPausesSchema::down(),
            ...WorkflowOutboxArchiveSchema::down(),
            ...WorkflowOutboxSchema::down(),
            ...WorkflowTimersSchema::down(),
            ...WorkflowCorrelationsSchema::down(),
            ...WorkflowInstancesSchema::down(),
        ];
    }
}
