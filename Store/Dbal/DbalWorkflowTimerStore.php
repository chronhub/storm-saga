<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowTimerRow;
use Storm\Saga\Store\WorkflowTimerStore;

/**
 * DBAL `WorkflowTimerStore` on `workflow_timers`. Arming is an idempotent `ON CONFLICT` upsert;
 * claiming is a single atomic `UPDATE … WHERE id IN (SELECT … FOR UPDATE SKIP LOCKED) RETURNING …`,
 * the same claim pattern as the outbox relay, so parallel runners get disjoint rows.
 *
 * @see \Storm\Clock\PointInTime::FORMAT
 */
final readonly class DbalWorkflowTimerStore implements WorkflowTimerStore
{
    /**
     * Advisory-lock key electing the single claimer per instant via the SafeHeadAdvancer election
     * pattern; hashed into Postgres's 64-bit advisory space by `hashtextextended`, with the dedicated
     * string keeping it distinct from the safe-head writer and outbox partition keys sharing that space.
     */
    private const string CLAIM_LOCK_KEY = 'storm.saga.timers.claim';

    public function __construct(
        private Connection $connection,
    ) {}

    public function arm(WorkflowId $id, string $stateKey, TimerKind $kind, PointInTime $fireAt): void
    {
        $this->guard(function () use ($id, $stateKey, $kind, $fireAt): null {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'INSERT INTO workflow_timers (workflow_type, correlation_id, state_key, kind, fire_at)
                 VALUES (:type, :corr, :state, :kind, :fire_at)
                 ON CONFLICT (workflow_type, correlation_id, state_key, kind)
                 DO UPDATE SET fire_at = EXCLUDED.fire_at, claimed_at = NULL,
                               attempts = 0, parked_at = NULL, last_error = NULL',
                [
                    'type' => $id->workflowType,
                    'corr' => $id->correlationId,
                    'state' => $stateKey,
                    'kind' => $kind->value,
                    'fire_at' => $fireAt->toString(),
                ],
            );

            return null;
        });
    }

    /**
     * {@inheritDoc}
     *
     * The lease default of 300s must comfortably exceed the slowest single step's wall-clock; otherwise
     * a slow step is re-claimed and double-driven, and while the second drive loses on the fence and OCC
     * `version`, the activity still runs twice. At-least-once by construction: activities must be
     * idempotent.
     *
     * The claim itself is advisory-elected, one claimer per instant per the SafeHeadAdvancer pattern: N
     * concurrent claimers on the same ordered leading edge each walk over the rows the others hold, since
     * `SKIP LOCKED` visits every locked row it skips, and that wasted walk dominates the claim's cost
     * under a flood of runners. A loser returns `[]` without scanning and simply polls again; the lock is
     * transaction-scoped to the claim's own short transaction, so it is released BEFORE the winner drives
     * its batch, so claims serialize while drives stay parallel.
     */
    public function claimDue(int $limit, PointInTime $now, int $leaseSeconds = 300): array
    {
        // derive the instants OUTSIDE the guard: a caller-side $now error is an input error that propagates
        // as itself, not a storage failure; only the stored-timestamp parse inside hydrate wraps.
        $nowStr = $now->toString();
        $leaseCutoff = $now->subSeconds(max(1, $leaseSeconds))->toString();

        return $this->guard(fn (): array => $this->connection->transactional(function (Connection $connection) use ($limit, $nowStr, $leaseCutoff): array {
            $wins = (bool) $connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 0))',
                ['key' => self::CLAIM_LOCK_KEY],
            );

            if (! $wins) {
                return []; // another runner is claiming this instant; drive later, never scan concurrently
            }

            $rows = $connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                'UPDATE workflow_timers SET claimed_at = :claimed
                 WHERE id IN (
                     SELECT t.id FROM workflow_timers t
                     WHERE t.fire_at <= :cutoff AND t.parked_at IS NULL
                       AND (t.claimed_at IS NULL OR t.claimed_at <= :lease_cutoff)
                       -- the operator freeze: the STATE timers of a paused saga are neither claimed
                       -- nor consumed nor moved, staying due at their ORIGINAL instants to fire at
                       -- the first cycle after the resume. The GLOBAL deadline claims through the
                       -- pause on purpose: the hard cap is not negotiable by an operator window.
                       AND (t.kind = \'global\' OR (
                            NOT EXISTS (SELECT 1 FROM workflow_pauses p WHERE p.workflow_type = t.workflow_type)
                            AND NOT EXISTS (
                                SELECT 1 FROM workflow_instances i
                                WHERE i.workflow_type = t.workflow_type AND i.correlation_id = t.correlation_id
                                  AND i.paused_at IS NOT NULL
                            )
                       ))
                     ORDER BY t.fire_at
                     LIMIT :limit
                     FOR UPDATE SKIP LOCKED
                 )
                 RETURNING id, workflow_type, correlation_id, state_key, kind, fire_at',
                [
                    'claimed' => $nowStr,
                    'cutoff' => $nowStr,
                    'lease_cutoff' => $leaseCutoff,
                    'limit' => $limit,
                ],
                ['limit' => ParameterType::INTEGER],
            );

            return array_map(self::hydrate(...), $rows);
        }));
    }

    public function cancel(WorkflowId $id, string $stateKey): void
    {
        $this->guard(function () use ($id, $stateKey): null {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'DELETE FROM workflow_timers WHERE workflow_type = :type AND correlation_id = :corr AND state_key = :state',
                ['type' => $id->workflowType, 'corr' => $id->correlationId, 'state' => $stateKey],
            );

            return null;
        });
    }

    public function recordFailure(int $id, string $error): int
    {
        return $this->guard(fn (): int => (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            'UPDATE workflow_timers SET attempts = attempts + 1, last_error = :error
             WHERE id = :id
             RETURNING attempts',
            ['error' => $error, 'id' => $id],
            ['id' => ParameterType::INTEGER],
        ));
    }

    public function park(int $id, string $error): void
    {
        $this->guard(function () use ($id, $error): null {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'UPDATE workflow_timers SET parked_at = clock_timestamp(), last_error = :error
                 WHERE id = :id',
                ['error' => $error, 'id' => $id],
                ['id' => ParameterType::INTEGER],
            );

            return null;
        });
    }

    public function unpark(int $id): bool
    {
        return $this->guard(function () use ($id): bool {
            // `parked_at IS NOT NULL` in the WHERE, never a read-then-write: the refusal to "repair"
            // a row that was never parked IS the predicate, so a concurrent arm() cannot slip between
            // a check and an update and have this reset a budget the arm just granted
            $unparked = $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'UPDATE workflow_timers SET parked_at = NULL, attempts = 0, last_error = NULL
                 WHERE id = :id AND parked_at IS NOT NULL',
                ['id' => $id],
                ['id' => ParameterType::INTEGER],
            );

            return $unparked > 0;
        });
    }

    public function fireAt(WorkflowId $id, string $stateKey, TimerKind $kind): ?PointInTime
    {
        return $this->guard(function () use ($id, $stateKey, $kind): ?PointInTime {
            $raw = $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT fire_at FROM workflow_timers
                 WHERE workflow_type = :type AND correlation_id = :corr AND state_key = :state AND kind = :kind',
                ['type' => $id->workflowType, 'corr' => $id->correlationId, 'state' => $stateKey, 'kind' => $kind->value],
            );

            return $raw === false ? null : PointInTime::fromStorage((string) $raw);
        });
    }

    public function listFor(WorkflowId $id): array
    {
        return $this->guard(function () use ($id): array {
            $rows = $this->connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                'SELECT id, workflow_type, correlation_id, state_key, kind, fire_at
                 FROM workflow_timers WHERE workflow_type = :type AND correlation_id = :corr ORDER BY fire_at',
                ['type' => $id->workflowType, 'corr' => $id->correlationId],
            );

            return array_map(self::hydrate(...), $rows);
        });
    }

    /**
     * Translate every driver failure, and a stored-timestamp parse error from hydrate, to the
     * port-owned `SagaStorageFailure`. A caller-side input error such as an invalid `$now` is derived
     * BEFORE the guard, so it propagates as itself and is not a storage failure.
     *
     * The `InvalidDateTimeException` arm wraps a corrupt stored timestamp from hydrate's `fromStorage`.
     * The column is `timestamptz`, so the value Postgres hands back always parses; the arm answers for
     * the PORT, whose contract admits any backend, and it is proven with a stubbed connection.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @infection-ignore-all
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Exception|InvalidDateTimeException $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidDateTimeException when the stored `fire_at` is unparseable; wrapped by the guard
     */
    private static function hydrate(array $row): WorkflowTimerRow
    {
        return new WorkflowTimerRow(
            (int) $row['id'],
            (string) $row['workflow_type'],
            (string) $row['correlation_id'],
            (string) $row['state_key'],
            TimerKind::from((string) $row['kind']),
            PointInTime::fromStorage((string) $row['fire_at']),
        );
    }
}
