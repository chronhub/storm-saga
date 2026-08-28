<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Message\Header;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\CorrelationAlreadyConsumed;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CorrelationReuse;
use Storm\Support\Dbal\BatchedDelete;

/**
 * DBAL `WorkflowInstanceStore` on `workflow_instances`. The JSON bags are encoded explicitly with
 * `json_encode` and cast to `jsonb` in the SQL. `update` is OCC: it writes `WHERE version = :version`,
 * bumps `version`, and treats "0 rows affected" as a conflict.
 */
final readonly class DbalWorkflowInstanceStore implements WorkflowInstanceStore
{
    /**
     * The ceiling on a saga's four JSON bags TOGETHER, encoded. One PostgreSQL page: past it a row
     * cannot sit in a page even alone, which for a state the engine rewrites on every single step is
     * a design signal, not a tuning one. Chosen against measurement rather than taste: the largest
     * real state across 1 228 187 dogfood instances is 308 bytes, so this leaves a factor of 26 for a
     * legitimately rich state while still catching a bag that grows one entry per step.
     */
    private const int MAX_STATE_BYTES = 8192;

    public function __construct(
        private Connection $connection,
    ) {}

    public function find(WorkflowId $id): ?WorkflowInstanceRow
    {
        return $this->guard(function () use ($id): ?WorkflowInstanceRow {
            $row = $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                'SELECT workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at, paused_at, paused_reason
                 FROM workflow_instances WHERE workflow_type = :type AND correlation_id = :corr',
                ['type' => $id->workflowType, 'corr' => $id->correlationId],
            );

            return $row === false ? null : self::hydrate($row);
        });
    }

    /**
     * {@inheritDoc}
     *
     * One saga per correlation is a schema-ENFORCED invariant via the unique index on `correlation_id`;
     * a second type claiming an owned correlation fails loudly at `create()` with
     * `CorrelationAlreadyOwned`, so this `LIMIT 1` can never pick an arbitrary row. Lifting the invariant
     * to allow multi-saga per correlation means dropping the index AND making this routing type-aware,
     * in one change.
     */
    public function findByCorrelation(string $correlationId): ?WorkflowInstanceRow
    {
        return $this->guard(function () use ($correlationId): ?WorkflowInstanceRow {
            $row = $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                'SELECT workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at, paused_at, paused_reason
                 FROM workflow_instances WHERE correlation_id = :corr LIMIT 1',
                ['corr' => $correlationId],
            );

            return $row === false ? null : self::hydrate($row);
        });
    }

    public function create(WorkflowInstanceRow $row, CorrelationReuse $reuse = CorrelationReuse::Reject): int
    {
        try {
            // the run number, READ before either insert because the instance row must carry it too. This
            // is not the look-then-write the claim deliberately avoids: nothing here decides whether the
            // birth is ALLOWED, only what to number it, and a racing pair that computes the same number
            // collides on the composite primary key. The refusal stays the schema's.
            // The `(int)` is for the type checker, not the arithmetic: PHP coerces the driver's numeric
            // string, and `false`/`null` for "no row" both add as 0, so Infection's CastInt mutant here
            // is PROVEN EQUIVALENT and deliberately left alive rather than ignored by config.
            // @infection-ignore-all; equivalent: the driver hands back a numeric string and PHP adds it as a number, so the cast documents the intent rather than changing the value
            $generation = 1 + (int) $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT COALESCE(max(generation), 0) FROM workflow_correlations WHERE correlation_id = :corr',
                ['corr' => $row->correlationId],
            );

            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'INSERT INTO workflow_instances (workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at)
                 VALUES (:type, :corr, :state, :status, CAST(:vars AS jsonb), CAST(:retries AS jsonb), CAST(:compensations AS jsonb), CAST(:context AS jsonb), :version, :generation, :def_version, :state_version, :retry_total, :retimes, CAST(:arms AS jsonb), CAST(:families AS jsonb), CAST(:parked AS jsonb),
                         COALESCE(CAST(:started_at AS timestamptz), clock_timestamp()), CAST(:waived_at AS timestamptz))',
                [...$this->columns($row), 'started_at' => $row->startedAt?->toString(), 'def_version' => $row->definitionVersion, 'generation' => $generation],
                ['version' => ParameterType::INTEGER, 'def_version' => ParameterType::INTEGER, 'state_version' => ParameterType::INTEGER, 'retry_total' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER],
            );
            // the durable claim, second on purpose: a LIVING duplicate must be reported as owned, and it
            // trips the instance index above before ever reaching here. What lands here is the reuse of a
            // correlation whose run ended and whose instance the prune deleted. The step's transaction
            // makes the pair atomic, the birth being written by the executor inside it, so a refused claim
            // takes the instance row down with it.
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'INSERT INTO workflow_correlations (correlation_id, generation, workflow_type, definition_version, reuse, claimed_at)
                 VALUES (:corr, :generation, :type, :def_version, :reuse, COALESCE(CAST(:started_at AS timestamptz), clock_timestamp()))',
                [
                    'corr' => $row->correlationId,
                    'generation' => $generation,
                    'type' => $row->workflowType,
                    'def_version' => $row->definitionVersion,
                    'reuse' => $reuse->value,
                    'started_at' => $row->startedAt?->toString(),
                ],
                ['def_version' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER],
            );

            return $generation;
        } catch (UniqueConstraintViolationException $e) {
            // the schema's one-saga-per-correlation invariant: another TYPE owns this correlation id.
            // A same-type duplicate hits the PK, workflow_instances_pk, and stays a storage
            // translation below: the engine's start policy already treats an existing row as
            // AlreadyStarted, so only the create.vs.create race lands here for the PK.
            if (str_contains($e->getMessage(), 'workflow_instances_correlation_uq')) {
                throw CorrelationAlreadyOwned::by($row->workflowType, $row->correlationId);
            }

            // the registry's reject index: this correlation already spent its one and only run. The
            // composite pk is the numbering backstop instead, a racing pair that computed the same
            // generation, which retrying DOES fix, so it stays a storage failure below.
            if (str_contains($e->getMessage(), 'workflow_correlations_spent_uq')) {
                throw CorrelationAlreadyConsumed::by($row->workflowType, $row->correlationId);
            }

            throw SagaStorageFailure::unavailable($e);
        } catch (Exception|JsonException $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    public function update(WorkflowInstanceRow $row): void
    {
        $affected = $this->guard(fn (): int|string => $this->connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE workflow_instances
             SET state_key = :state, status = :status, vars = CAST(:vars AS jsonb),
                 retries = CAST(:retries AS jsonb), compensations = CAST(:compensations AS jsonb), context = CAST(:context AS jsonb),
                 retry_total = :retry_total, retimes = :retimes, arms = CAST(:arms AS jsonb), families = CAST(:families AS jsonb), parked = CAST(:parked AS jsonb), waived_at = CAST(:waived_at AS timestamptz), state_version = :state_version, version = version + 1, updated_at = clock_timestamp()
             WHERE workflow_type = :type AND correlation_id = :corr AND version = :version',
            $this->columns($row),
            ['version' => ParameterType::INTEGER, 'retry_total' => ParameterType::INTEGER, 'state_version' => ParameterType::INTEGER],
        ));

        if ($affected === 0) {
            throw StaleWorkflowInstance::forStep($row->workflowType, $row->correlationId, $row->version);
        }
    }

    public function delete(WorkflowId $id): void
    {
        $this->guard(function () use ($id): null {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'DELETE FROM workflow_instances WHERE workflow_type = :type AND correlation_id = :corr',
                ['type' => $id->workflowType, 'corr' => $id->correlationId],
            );

            return null;
        });
    }

    public function strandedByFailedEffect(int $limit = 1000): array
    {
        return $this->guard(function () use ($limit): array {
            $rows = $this->connection->fetchAllNumeric(
                /** @lang PostgreSQL */
                sprintf(
                    "SELECT DISTINCT o.correlation_id, o.header->>'%s' AS mid
                     FROM workflow_outbox o
                     JOIN workflow_instances i ON i.correlation_id = o.correlation_id
                     WHERE o.status = '%s' AND i.status = '%s'
                     ORDER BY o.correlation_id, mid
                     LIMIT :limit",
                    Header::MessageId->value,
                    OutboxStatus::Failed->value,
                    WorkflowStatus::Running->value,
                ),
                ['limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

            return array_map(
                static fn (array $row): array => [(string) $row[0], $row[1] === null ? null : (string) $row[1]],
                $rows,
            );
        });
    }

    public function waivedAndQuiet(int $quietForSeconds, int $limit = 100): array
    {
        return $this->guard(function () use ($quietForSeconds, $limit): array {
            $rows = $this->connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                "SELECT workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at, paused_at, paused_reason
                 FROM workflow_instances
                 WHERE status = 'running' AND waived_at IS NOT NULL
                   AND updated_at < now() - (CAST(:quiet AS bigint) * interval '1 second')
                 ORDER BY waived_at
                 LIMIT :limit",
                ['quiet' => $quietForSeconds, 'limit' => $limit],
                ['quiet' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
            );

            return array_map(self::hydrate(...), $rows);
        });
    }

    public function runningCountsByVersion(): array
    {
        return $this->guard(function (): array {
            $rows = $this->connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                "SELECT workflow_type, definition_version, count(*) AS n
                 FROM workflow_instances WHERE status = 'running'
                 GROUP BY workflow_type, definition_version",
            );

            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['workflow_type']][(int) $row['definition_version']] = (int) $row['n'];
            }

            return $map;
        });
    }

    public function countChildren(string $parentCorrelationId): int
    {
        return $this->guard(fn (): int => (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM workflow_instances WHERE parent_correlation_id = :parent',
            ['parent' => $parentCorrelationId],
        ));
    }

    public function pauseInstance(WorkflowId $id, ?string $reason): bool
    {
        return $this->guard(fn (): bool => $this->connection->executeStatement(
            /** @lang PostgreSQL */
            "UPDATE workflow_instances SET paused_at = clock_timestamp(), paused_reason = :reason
             WHERE workflow_type = :type AND correlation_id = :corr AND status = 'running' AND paused_at IS NULL",
            ['type' => $id->workflowType, 'corr' => $id->correlationId, 'reason' => $reason],
        ) > 0);
    }

    public function resumeInstance(WorkflowId $id): bool
    {
        return $this->guard(fn (): bool => $this->connection->transactional(function () use ($id): bool {
            $lifted = $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'UPDATE workflow_instances SET paused_at = NULL, paused_reason = NULL
                 WHERE workflow_type = :type AND correlation_id = :corr AND paused_at IS NOT NULL',
                ['type' => $id->workflowType, 'corr' => $id->correlationId],
            ) > 0;

            // same transaction as the lift: a failure between the two would otherwise leave the
            // saga active with its pre-pause claims leased out, and the retry of an already-lifted
            // resume could no longer reach the release
            if ($lifted) {
                $this->connection->executeStatement(
                    /** @lang PostgreSQL */
                    "UPDATE workflow_timers SET claimed_at = NULL
                     WHERE workflow_type = :type AND correlation_id = :corr
                       AND claimed_at IS NOT NULL AND parked_at IS NULL AND kind <> 'global'",
                    ['type' => $id->workflowType, 'corr' => $id->correlationId],
                );
            }

            return $lifted;
        }));
    }

    public function pauseType(string $workflowType, ?string $reason): void
    {
        $this->guard(fn (): int|string => $this->connection->executeStatement(
            // idempotent on purpose: the FIRST freeze's stamp and reason stand, a repeated verb
            // must not quietly rewrite the incident's audit trail
            /** @lang PostgreSQL */
            'INSERT INTO workflow_pauses (workflow_type, reason) VALUES (:type, :reason)
             ON CONFLICT (workflow_type) DO NOTHING',
            ['type' => $workflowType, 'reason' => $reason],
        ));
    }

    public function resumeType(string $workflowType): bool
    {
        return $this->guard(fn (): bool => $this->connection->transactional(function () use ($workflowType): bool {
            $lifted = $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'DELETE FROM workflow_pauses WHERE workflow_type = :type',
                ['type' => $workflowType],
            ) > 0;

            // same transaction as the lift, the fleet-wide twin of the instance resume's release
            if ($lifted) {
                $this->connection->executeStatement(
                    /** @lang PostgreSQL */
                    "UPDATE workflow_timers SET claimed_at = NULL
                     WHERE workflow_type = :type
                       AND claimed_at IS NOT NULL AND parked_at IS NULL AND kind <> 'global'",
                    ['type' => $workflowType],
                );
            }

            return $lifted;
        }));
    }

    public function pausedType(string $workflowType): bool
    {
        return $this->guard(fn (): bool => (bool) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT EXISTS (SELECT 1 FROM workflow_pauses WHERE workflow_type = :type)',
            ['type' => $workflowType],
        ));
    }

    public function countChildrenSerialized(string $parentCorrelationId): int
    {
        return $this->guard(function () use ($parentCorrelationId): int {
            // the advisory lock FIRST, xact-scoped so it dies with the birth's transaction: every
            // concurrent birth of this parent queues here, then counts what the one before it wrote
            $this->connection->executeQuery(
                /** @lang PostgreSQL */
                "SELECT pg_advisory_xact_lock(hashtextextended('storm-spawn:' || :parent, 0))",
                ['parent' => $parentCorrelationId],
            );

            return (int) $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT count(*) FROM workflow_instances WHERE parent_correlation_id = :parent',
                ['parent' => $parentCorrelationId],
            );
        });
    }

    public function spawnedMembers(string $parentCorrelationId, string $family): int
    {
        // The range is a PREFILTER, not the membership test. Deterministic member identities sort
        // as one contiguous range, `prefix<digits>` lying between `prefix0` and `prefix:` (':' being
        // the character after '9'), which the registry's primary key serves without a LIKE and
        // without escaping the app-owned correlation id. The bound is read in BYTES, which is what
        // `correlation_id COLLATE "C"` buys: under a libc locale collation punctuation weighs less
        // than digits, the upper bound sorts below the members, and the count comes back zero with
        // nothing to show for it.
        //
        // What the range CANNOT say is where the digits end, because it only constrains the first
        // character after the prefix: `prefix12abc` opens on a digit and sits inside the bounds like
        // a member. So the suffix carries the boundary, anchored at both ends, the same `^\d+$` the
        // in-memory twin applies; the range narrows the scan to a handful of rows and the pattern
        // decides among them. `char_length` is computed server-side so the offset needs no mbstring
        // and lands right after the prefix, every row in range starting with it by construction.
        $prefix = $parentCorrelationId.ChildCorrelation::DELIMITER.$family.'-';

        return $this->guard(fn (): int => (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            "SELECT count(DISTINCT correlation_id) FROM workflow_correlations
             WHERE correlation_id >= :lo AND correlation_id < :hi
               AND substring(correlation_id from char_length(:prefix) + 1) ~ '^[0-9]+$'",
            ['lo' => $prefix.'0', 'hi' => $prefix.':', 'prefix' => $prefix],
        ));
    }

    public function livingMembers(string $parentCorrelationId, string $family): int
    {
        // the same boundary as `spawnedMembers()`, and for the same reason: the range alone admits a
        // neighbouring slot that merely OPENS on a digit
        $prefix = $parentCorrelationId.ChildCorrelation::DELIMITER.$family.'-';

        return $this->guard(fn (): int => (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            "SELECT count(*) FROM workflow_instances
             WHERE parent_correlation_id = :parent AND status = 'running'
               AND correlation_id >= :lo AND correlation_id < :hi
               AND substring(correlation_id from char_length(:prefix) + 1) ~ '^[0-9]+$'",
            ['parent' => $parentCorrelationId, 'lo' => $prefix.'0', 'hi' => $prefix.':', 'prefix' => $prefix],
        ));
    }

    public function loadAdoptableParent(string $correlationId): ?WorkflowInstanceRow
    {
        return $this->guard(function () use ($correlationId): ?WorkflowInstanceRow {
            $row = $this->connection->fetchAssociative(
                // FOR SHARE, never FOR UPDATE: siblings born concurrently must not serialize against
                // each other, only against the parent's settle, which needs the row exclusively.
                /** @lang PostgreSQL */
                'SELECT workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at, paused_at, paused_reason
                 FROM workflow_instances WHERE correlation_id = :corr LIMIT 1 FOR SHARE',
                ['corr' => $correlationId],
            );

            return $row === false ? null : self::hydrate($row);
        });
    }

    public function livingChildren(string $parentCorrelationId): array
    {
        return $this->guard(function () use ($parentCorrelationId): array {
            $rows = $this->connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                "SELECT workflow_type, correlation_id, state_key, status, vars, retries, compensations, context, version, generation, definition_version, state_version, retry_total, retimes, arms, families, parked, started_at, waived_at, paused_at, paused_reason
                 FROM workflow_instances WHERE parent_correlation_id = :parent AND status = 'running' ORDER BY correlation_id",
                ['parent' => $parentCorrelationId],
            );

            return array_map(self::hydrate(...), $rows);
        });
    }

    /**
     * Count the terminal instances prunable by age; the dry-run preview of `pruneTerminal()`.
     * `completed` only by default; `$includeFailed` also counts `halted` and `compensated`.
     *
     * @throws SagaStorageFailure on a saga-store read failure
     */
    public function countTerminal(bool $includeFailed, int $ageSeconds): int
    {
        return $this->guard(fn (): int => (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'SELECT count(*) FROM workflow_instances WHERE '.self::terminalPredicate($includeFailed),
            ['age' => $ageSeconds],
        ));
    }

    /**
     * Prune the terminal instances older than `$ageSeconds`, in `$batch`-capped statements to avoid a
     * long lock. `completed` only by default; `$includeFailed` also prunes `halted` and `compensated`,
     * kept by default for forensics. A `running` saga is never touched.
     *
     * The row leaves, its claim stays: `workflow_correlations` is stamped with the outcome FIRST, so a
     * crash in the window leaves a stamped-but-unpruned instance, which is harmless and re-stamps
     * identically on the next run. The stamp reads `updated_at`, the instant the saga actually settled,
     * not the instant this prune ran, so a late cleanup does not rewrite history. It deliberately stamps
     * every terminal row matching the predicate, including those `SKIP LOCKED` leaves behind this pass:
     * the outcome is true whether or not the hot row has gone yet.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws SagaStorageFailure on a saga-store stamp or delete failure
     */
    public function pruneTerminal(bool $includeFailed, int $ageSeconds, int $batch): int
    {
        return $this->guard(function () use ($includeFailed, $ageSeconds, $batch): int {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                sprintf(
                    'UPDATE workflow_correlations c
                     SET closed_at = i.updated_at, final_status = i.status
                     FROM workflow_instances i
                     WHERE i.correlation_id = c.correlation_id AND c.closed_at IS NULL AND %s',
                    self::terminalPredicate($includeFailed, 'i.'),
                ),
                ['age' => $ageSeconds],
            );

            return BatchedDelete::run(
                $this->connection,
                'workflow_instances',
                self::terminalPredicate($includeFailed),
                ['age' => $ageSeconds],
                $batch,
            );
        });
    }

    /**
     * The prunable-terminal predicate: terminal statuses gated by age. The status list is a fixed
     * framework set, never input: `completed` only, or also `halted` and `compensated` when failures
     * are included. `$qualifier` prefixes the columns for the multi-relation statement, the registry
     * stamp, where leaving them bare would bind by whichever table happens not to have them.
     */
    private static function terminalPredicate(bool $includeFailed, string $qualifier = ''): string
    {
        $statuses = $includeFailed ? "'completed', 'halted', 'compensated'" : "'completed'";

        return sprintf(
            "%1\$sstatus IN (%2\$s) AND %1\$supdated_at < now() - (CAST(:age AS bigint) * interval '1 second')",
            $qualifier,
            $statuses,
        );
    }

    /**
     * Translate every driver or codec failure to the port-owned `SagaStorageFailure`; the port's own
     * semantics, `StaleWorkflowInstance` raised by the callers, never reach this catch.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Exception|JsonException|ClockExceptionContract $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException when a stored bag is not valid JSON
     * @throws ClockExceptionContract when `started_at` cannot be parsed
     */
    private static function hydrate(array $row): WorkflowInstanceRow
    {
        /** @var array<string, mixed> $vars */
        $vars = json_decode((string) $row['vars'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, int|array{n: int, since: string|null}> $decodedRetries */
        $decodedRetries = json_decode((string) $row['retries'], true, flags: JSON_THROW_ON_ERROR);
        $retries = self::normalizeRetries($decodedRetries);
        /** @var list<array{step: string, status: string, confirmed?: bool, degraded?: bool, reason?: string|null, at?: string|null}> $compensationRows the toArray() round-trip shape */
        $compensationRows = json_decode((string) $row['compensations'], true, flags: JSON_THROW_ON_ERROR);
        $compensations = array_map(CompensationRecord::fromArray(...), $compensationRows);
        /** @var array<string, mixed> $context */
        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array{state: string, event: class-string, cause: string|null}|null $parked */
        $parked = $row['parked'] === null ? null : json_decode((string) $row['parked'], true, flags: JSON_THROW_ON_ERROR);

        return new WorkflowInstanceRow(
            (string) $row['workflow_type'],
            (string) $row['correlation_id'],
            (string) $row['state_key'],
            WorkflowStatus::from((string) $row['status']),
            $vars,
            $retries,
            $context,
            (int) $row['version'],
            PointInTime::fromStorage((string) $row['started_at']),
            $compensations,
            definitionVersion: (int) $row['definition_version'],
            retryTotal: (int) $row['retry_total'],
            generation: (int) $row['generation'],
            waivedAt: $row['waived_at'] === null ? null : PointInTime::fromStorage((string) $row['waived_at']),
            stateVersion: (int) $row['state_version'],
            retimes: (int) $row['retimes'],
            arms: (array) json_decode((string) $row['arms'], true, flags: JSON_THROW_ON_ERROR),
            families: (array) json_decode((string) $row['families'], true, flags: JSON_THROW_ON_ERROR),
            parked: $parked,
            pausedAt: $row['paused_at'] === null ? null : PointInTime::fromStorage((string) $row['paused_at']),
            // the `(string)` here is an equivalent mutant, the column being `text` and the driver
            // handing it back as a PHP string; the cast answers the analyser, the fetch being typed
            // `mixed`. Left UN-ignored on purpose: the ternary on this same line IS killed by a test,
            // and a line-level ignore would mask that sibling.
            pausedReason: $row['paused_reason'] === null ? null : (string) $row['paused_reason'],
        );
    }

    /**
     * The retries bag's ENGINE migration, applied lazily at the read boundary: the bag changed shape
     * from bare attempt counts, `{state: n}`, to visit ledgers, `{state: {n, since}}`, and this bag
     * belongs to the engine, so the application's `migrateState()`/`stateVersion` machinery does not
     * cover it. A legacy count hydrates with a null `since`, an honest "window unknown" the runner
     * resolves by opening the clock at the row's next retry; the next write persists the new shape.
     *
     * @param  array<string, int|array{n: int, since: string|null}>  $retries
     * @return array<string, array{n: int, since: string|null}>
     */
    private static function normalizeRetries(array $retries): array
    {
        return array_map(
            static fn (int|array $entry): array => is_int($entry) ? ['n' => $entry, 'since' => null] : $entry,
            $retries,
        );
    }

    /**
     * @return array<string, scalar|null>
     *
     * @throws JsonException when a state bag cannot be encoded
     */
    private function columns(WorkflowInstanceRow $row): array
    {
        $columns = [
            'type' => $row->workflowType,
            'corr' => $row->correlationId,
            'state' => $row->stateKey,
            'status' => $row->status->value,
            'vars' => json_encode($row->vars, JSON_THROW_ON_ERROR),
            'retries' => json_encode($row->retries, JSON_THROW_ON_ERROR),
            // array_map(toArray) is the explicit persisted contract; the UnwrapArrayMap mutant, which
            // encodes the records raw, is equivalent; json_encode of a CompensationRecord emits the same shape as
            // toArray(): the same public props in order, and the enum encodes to its value.
            'compensations' => json_encode(array_map(static fn (CompensationRecord $record): array => $record->toArray(), $row->compensations), JSON_THROW_ON_ERROR),
            'context' => json_encode($row->context, JSON_THROW_ON_ERROR),
            'version' => $row->version,
            'retry_total' => $row->retryTotal,
            // the lifetime retime counter, mutable like retry_total, bound on INSERT and UPDATE
            'retimes' => $row->retimes,
            // the per-join arrival ledgers; '{}' when empty so the jsonb column reads as an object
            'arms' => $row->arms === [] ? '{}' : json_encode($row->arms, JSON_THROW_ON_ERROR),
            // the per-family expected member counts, the same object-shape discipline
            'families' => $row->families === [] ? '{}' : json_encode($row->families, JSON_THROW_ON_ERROR),
            // the crossing an indexed family's gate rested and owes back; NULL when nothing is owed,
            // which is every row outside that one wait, so the column stays empty in the common case
            'parked' => $row->parked === null ? null : json_encode($row->parked, JSON_THROW_ON_ERROR),
            'waived_at' => $row->waivedAt?->toString(),
            // threaded by every step mover, bumped only by the migration chain; bound on INSERT and
            // UPDATE alike so a migrated bag and its version land in the same write
            'state_version' => $row->stateVersion,
        ];

        self::guardStateSize($row, $columns);

        return $columns;
    }

    /**
     * Refuse a state that has stopped being one. Checked HERE because this is where the four bags are
     * already encoded, so the measurement is free, and because it covers both writes: `create()` and
     * every `update()`.
     *
     * On the total rather than per bag: what the row pays is the sum, and `update()` rewrites all four
     * in a single statement, so a step's cost is the whole state every time. The message still names
     * each bag, since the total says there is a problem and only the breakdown says where.
     *
     * @param  array<string, mixed>  $columns
     *
     * @throws SagaStateTooLarge when the encoded bags exceed the cap
     */
    private static function guardStateSize(WorkflowInstanceRow $row, array $columns): void
    {
        $sizes = [];
        $total = 0;
        foreach (['vars', 'retries', 'compensations', 'context'] as $bag) {
            $bytes = strlen((string) $columns[$bag]);
            $sizes[$bag] = $bytes;
            $total += $bytes;
        }

        if ($total > self::MAX_STATE_BYTES) {
            throw SagaStateTooLarge::forInstance($row->workflowType, $row->correlationId, $total, self::MAX_STATE_BYTES, $sizes);
        }
    }
}
