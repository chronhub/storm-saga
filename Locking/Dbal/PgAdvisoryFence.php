<?php

declare(strict_types=1);

namespace Storm\Saga\Locking\Dbal;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Store\WorkflowId;

/**
 * Postgres advisory-lock `SagaStepUnitOfWork`. It takes a transaction-scoped lock `pg_try_advisory_xact_lock`
 * inside a single `transactional()` it owns, then runs `$work` in that same transaction, so the lock
 * releases exactly at commit or rollback and never spans more than one step.
 *
 * The lock key is a single int8 that Postgres derives via `hashtextextended` from `workflowType` and
 * `correlationId`, joined by a separator that cannot occur in either, so distinct pairs never collide
 * on concatenation; `a|bc` cannot be read as `ab|c`.
 *
 * Top-level by assumption: if the caller has ALREADY opened a transaction on this connection, an
 * ambient one such as a delivery seam wrapping its handlers, DBAL `transactional()` nests as a
 * SAVEPOINT. The advisory lock is `xact`-scoped to the top-level transaction, not the savepoint, so
 * it is then held until the OUTER commit and the fence owns more than one step's worth of transaction.
 * The one-step guarantee is stated for a top-level step transaction; see `StepExecutor`'s announcement
 * caveat for the visible consequence.
 */
final readonly class PgAdvisoryFence implements SagaStepUnitOfWork
{
    /** ASCII unit separator, absent from class FQCNs and correlation ids. */
    private const string KEY_SEPARATOR = "\x1f";

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure acquiring the advisory lock or the wrapping transaction
     */
    public function tryWithin(WorkflowId $id, Closure $work): bool
    {
        $key = $id->workflowType.self::KEY_SEPARATOR.$id->correlationId;

        return $this->connection->transactional(
            static function (Connection $connection) use ($key, $work): bool {
                $locked = (bool) $connection->fetchOne(
                    /** @lang PostgreSQL */
                    'SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 0))',
                    ['key' => $key],
                );

                if (! $locked) {
                    return false; // another worker holds it, so skip; the caller re-kicks
                }

                $work();

                return true;
            },
        );
    }
}
