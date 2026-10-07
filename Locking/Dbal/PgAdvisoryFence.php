<?php

declare(strict_types=1);

namespace Storm\Saga\Locking\Dbal;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\TransactionIsolationLevel;
use Storm\Saga\Exception\FenceIsolationRefused;
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
 *
 * `READ COMMITTED` is an applied boundary, not an assumption. The family gate's member counts are plain
 * reads, fresh per statement under that level only; under `REPEATABLE READ` or `SERIALIZABLE` a child
 * committed after the snapshot is missed with no error to say so. The effective level is read from
 * Postgres in the same statement as the lock, so a level set by raw SQL or a pooler costs no extra
 * round trip to detect, and any level but `READ COMMITTED` is refused before the step runs. A
 * transaction the fence owns is pinned to `READ COMMITTED` beforehand when DBAL's tracked level
 * diverges, and the tracked level is restored afterwards; an ambient transaction is the caller's and
 * is only read.
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
        $ambient = $this->connection->isTransactionActive();

        // the pin is conditional on DBAL's TRACKED level, a local read that costs no statement: the
        // common case is already READ COMMITTED and pays nothing. A level set behind DBAL's back is
        // invisible here and is caught by the effective read below instead
        $previous = null;
        if (! $ambient && $this->connection->getTransactionIsolation() !== TransactionIsolationLevel::READ_COMMITTED) {
            $previous = $this->connection->getTransactionIsolation();
            $this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
        }

        try {
            return $this->connection->transactional(
                static function (Connection $connection) use ($key, $work, $ambient): bool {
                    // one statement for both: the lock, and the level Postgres really runs this
                    // transaction under, which DBAL's local tracking cannot know
                    $row = $connection->fetchNumeric(
                        /* language=PostgreSQL */
                        "SELECT pg_try_advisory_xact_lock(hashtextextended(:key, 0)), current_setting('transaction_isolation')",
                        ['key' => $key],
                    );
                    $locked = $row !== false && (bool) $row[0];
                    $isolation = $row === false ? '' : (string) $row[1];

                    if ($isolation !== 'read committed') {
                        throw $ambient
                            ? FenceIsolationRefused::underAmbientTransaction($isolation)
                            : FenceIsolationRefused::underOwnedTransaction($isolation);
                    }

                    if (! $locked) {
                        return false; // another worker holds it, so skip; the caller re-kicks
                    }

                    $work();

                    return true;
                },
            );
        } finally {
            // restored last, on every exit: the connection is borrowed and the level it carried
            // must not be left downgraded for whatever shares it after the step
            if ($previous !== null) {
                $this->connection->setTransactionIsolation($previous);
            }
        }
    }
}
