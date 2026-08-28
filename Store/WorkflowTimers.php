<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Clock\PointInTime;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Locking\SagaStepUnitOfWork;

/**
 * The step's timer capability: arm, read, cancel and list the durable timers of one saga. The
 * executor's half of the timer store; the runner's claim-and-lease half is `DueTimerQueue`. Writes
 * MUST enlist in the step's single unit of work per `SagaStepUnitOfWork` law 1, and every method may throw the
 * port-owned `SagaStorageFailure` with the driver's failure wrapped.
 *
 * @see DueTimerQueue
 * @see SagaStepUnitOfWork
 * @see SagaStorageFailure
 */
interface WorkflowTimers
{
    /** Reserved `state_key` for the instance-wide global-deadline timer; no real state uses it. */
    public const string GLOBAL_KEY = '__global__';

    /**
     * Idempotently schedule a `$kind` for `(id, $stateKey)` at `$fireAt`; re-arming the same key just
     * moves `fire_at` and clears any claim, PARKING and attempts included, so a fresh arm is a fresh
     * budget and the failures of a previous life are not this arm's.
     */
    public function arm(WorkflowId $id, string $stateKey, TimerKind $kind, PointInTime $fireAt): void;

    /**
     * The LIVE fire instant of `(id, $stateKey, $kind)`; the freshness input of the executor's
     * stale-timer guard: a claimed copy whose live row has moved to the future was re-armed after the
     * claim, a wake having pushed the deadline, and must not drive. Null when no row exists.
     */
    public function fireAt(WorkflowId $id, string $stateKey, TimerKind $kind): ?PointInTime;

    /**
     * Drop every timer for `(id, $stateKey)`, called when the saga transitions out of that state.
     */
    public function cancel(WorkflowId $id, string $stateKey): void;

    /**
     * List all timers for a given workflow.
     *
     * @return list<WorkflowTimerRow>
     */
    public function listFor(WorkflowId $id): array;
}
