<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Clock\PointInTime;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The runner's timer capability: claim the due rows under a lease, record failed drives, park the
 * poison, and return a parked row to the claim. The recovery half of the timer store; the step's
 * arm-and-cancel half is `WorkflowTimers`. Every method may throw the port-owned
 * `SagaStorageFailure` with the driver's failure wrapped.
 *
 * @see WorkflowTimers
 * @see SagaStorageFailure
 */
interface DueTimerQueue
{
    /**
     * Atomically claim up to `$limit` rows due at `$now` via `FOR UPDATE SKIP LOCKED`, so parallel
     * runners never double-claim, and return them for dispatch.
     *
     * The claim is a lease, not a permanent mark: a row claimed more than `$leaseSeconds` ago is
     * re-claimable. So a runner that crashed, raced a busy fence, or otherwise didn't finish processing
     * a timer gets it retried on a later sweep; this is what makes the timer runner the recovery agent,
     * since a claimed-but-unprocessed timer is never silently lost. A successfully handled timer is
     * removed by the step, canceled on transition or re-armed on retry, before the lease matters.
     *
     * @param  positive-int  $limit
     * @param  positive-int  $leaseSeconds  re-claim a row whose previous claim is older than this
     * @return list<WorkflowTimerRow>
     */
    public function claimDue(int $limit, PointInTime $now, int $leaseSeconds = 300): array;

    /**
     * Record one FAILED drive of the row on the runner's throw path: bump `attempts`, keep the error as
     * forensics, and return the new count; the runner parks the row when it exhausts its budget.
     * Distinct from the claim, since a claim is not a failure and a fence-raced drive that returns false
     * costs nothing.
     *
     * @return int the attempts count after this failure
     */
    public function recordFailure(int $id, string $error): int;

    /**
     * Quarantine the row OUT of the claim: a permanent failure whose workflow was purged while the timer
     * survived, or an exhausted attempts budget. A parked row is never claimed again, so one poison
     * timer can no longer crash-loop the daemon while its co-claimed siblings wait out the lease forever.
     * Un-parked by a fresh `arm()`, whose upsert resets it, or by `unpark()` when an operator judges the
     * cause fixed; a settle's cancel drops it like any other row.
     */
    public function park(int $id, string $error): void;

    /**
     * Return a parked row to the claim: clear the quarantine, reset the attempts budget, drop the stale
     * error. The operator's half of `park()`, for the common case where the cause was outside the row,
     * a downstream that was down or a bug since fixed, and the timer would otherwise stay frozen for
     * the life of its saga.
     *
     * Safe by nature, and the reason this needs no evidence check: a parked timer never fired, so it
     * committed no effect and un-parking cannot double one. It only becomes claimable again.
     *
     * Refuses silently-nothing rather than lying: `false` when no row carries the id, and when the row
     * exists but was not parked, which is not a repair and must not read as one.
     *
     * @return bool whether a parked row was actually returned to the claim
     */
    public function unpark(int $id): bool;
}
