<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\CircuitBreaker\BreakerSnapshot;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * Default `CircuitBreakerStorage`: a Postgres `circuit_breaker` table, on-brand like the outbox, timers
 * and inbox, with no extra infrastructure. Writes are atomic via `INSERT … ON CONFLICT DO UPDATE`, so
 * concurrent workers count failures and trip the breaker without a read-modify-write race.
 *
 * Every driver or codec failure translates to the port-owned `SagaStorageFailure` with the cause
 * chained, the same discipline as the co-transactional group's adapters: a breaker outage surfaces as a
 * saga storage failure through the runner, never as a raw driver type.
 */
final readonly class DbalCircuitBreakerStorage implements CircuitBreakerStorage
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the storage fails, the stored `opened_at` cannot be parsed, or
     *                            the stored `state` is outside the enum
     */
    public function read(string $key): BreakerSnapshot
    {
        return $this->guard(function (string $key): BreakerSnapshot {
            $row = $this->connection->fetchAssociative(
                'SELECT state, failures, opened_at FROM circuit_breaker WHERE key = :key',
                ['key' => $key],
            );

            if ($row === false) {
                return new BreakerSnapshot(BreakerState::Closed, 0);
            }

            // a value outside the enum is corruption, and it refuses in the port's taxonomy, like the
            // Redis adapter, never as a raw ValueError. The column's CHECK narrows what can be stored
            // here; the guard answers for the PORT, whose other backend carries no such net
            // @infection-ignore-all; equivalent: the column is `text` and the driver hands it back as a
            // PHP string, so the cast normalizes nothing at runtime; it is there for the analyser, the
            // fetch being typed `mixed`. Ignored rather than left visible because this line carries no
            // other mutant to mask.
            $state = (string) $row['state'];
            $stateEnum = BreakerState::tryFrom($state)
                ?? throw SagaStorageFailure::corrupted(sprintf("state '%s' under '%s' is not a breaker state", $state, $key));

            return new BreakerSnapshot(
                $stateEnum,
                (int) $row['failures'],
                $row['opened_at'] !== null ? PointInTime::fromStorage((string) $row['opened_at']) : null,
            );
        }, $key);
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the storage fails
     */
    public function recordSuccess(string $key): void
    {
        $closed = BreakerState::Closed->value;

        // A plain CONDITIONAL update, not an upsert: an UPDATE locks only the rows its WHERE matches, so an
        // already-closed/0 breaker, the common case on every guarded call of a healthy resource, matches
        // nothing and takes NO row lock. An `INSERT … ON CONFLICT DO UPDATE`, even guarded by a
        // WHERE, would lock the singleton row per call to evaluate the conflict, serializing every worker
        // on it. No INSERT needed: an absent row already reads as
        // Closed/0, so a success on a never-failed breaker is a genuine no-op. Only a real
        // reset, half-open/open to closed, or failures > 0, matches the WHERE and writes.
        $this->guard(function (string $key) use ($closed): null {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                "UPDATE circuit_breaker SET state = '$closed', failures = 0, opened_at = NULL
                 WHERE key = :key AND (state <> '$closed' OR failures <> 0)",
                ['key' => $key],
            );

            return null;
        }, $key);
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the storage fails
     */
    public function recordFailure(string $key, int $threshold): void
    {
        $open = BreakerState::Open->value;
        $closed = BreakerState::Closed->value;

        $this->guard(function (string $key) use ($open, $closed, $threshold): null {
            // positional params: the threshold is compared four times; '?' avoids reusing a named placeholder
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                "INSERT INTO circuit_breaker (key, state, failures, opened_at)
                 VALUES (?, CASE WHEN 1 >= ? THEN '$open' ELSE '$closed' END, 1, CASE WHEN 1 >= ? THEN clock_timestamp() ELSE NULL END)
                 ON CONFLICT (key) DO UPDATE SET
                     failures   = circuit_breaker.failures + 1,
                     state      = CASE WHEN circuit_breaker.failures + 1 >= ? THEN '$open' ELSE circuit_breaker.state END,
                     opened_at  = CASE WHEN circuit_breaker.failures + 1 >= ? THEN clock_timestamp() ELSE circuit_breaker.opened_at END",
                [$key, $threshold, $threshold, $threshold, $threshold],
            );

            return null;
        }, $key);
    }

    /**
     * Translate every driver failure, and a corrupt stored `opened_at`, to the port-owned
     * `SagaStorageFailure`, the module-wide adapter discipline.
     *
     * @template T
     *
     * @param  callable(string): T  $operation
     * @return T
     */
    private function guard(callable $operation, string $key): mixed
    {
        try {
            return $operation($key);
        } catch (Exception|InvalidDateTimeException $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }
}
