<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The operator-freeze capability of the saga store: stamp and lift the instance-level pause, and
 * register and delete the type-level one. The pause and resume surfaces write here; the step only
 * ever reads `pausedType()`. Every method may throw the port-owned `SagaStorageFailure` with the
 * driver's failure wrapped.
 *
 * @see SagaStorageFailure
 */
interface WorkflowPauses
{
    /**
     * Stamp the INSTANCE-level operator freeze: `paused_at` and its reason, on a RUNNING row only.
     * The one writer of the pair: no step mover touches it, and the step's own UPDATE does not
     * write it, so the stamp cannot be clobbered by a step in flight. Returns false when the row is
     * absent, terminal, or already paused: the verb reports instead of guessing.
     */
    public function pauseInstance(WorkflowId $id, ?string $reason): bool;

    /**
     * Clear the instance-level freeze and, in the same atomic unit, return the saga's claimed,
     * unparked state timers to the claimable pool; the global deadline is untouched, since it
     * claims and drives through a pause. Returns false when the row is absent or not paused, and
     * then releases nothing: an unfrozen saga's claims are never disturbed, so the verb is safely
     * retryable after an incident. The resumed saga's due timers kept their ORIGINAL instants, so
     * a deadline that expired during the window fires at the next timer cycle instead of waiting
     * out its lease; a released row that was in fact mid-drive only costs a second drive the fence
     * and the stale guards absorb, the engine's at-least-once contract.
     */
    public function resumeInstance(WorkflowId $id): bool;

    /**
     * Register the TYPE-level freeze: one row in `workflow_pauses`, idempotent on re-pause, the
     * first stamp and reason standing. Gates execution AND births for every instance of the type.
     */
    public function pauseType(string $workflowType, ?string $reason): void;

    /**
     * Delete the type-level freeze and, in the same atomic unit, return the type's claimed,
     * unparked state timers to the claimable pool, the fleet-wide twin of `resumeInstance()`.
     * Returns false when the type was not paused, and then releases nothing.
     */
    public function resumeType(string $workflowType): bool;

    /**
     * Whether the type is frozen in `workflow_pauses`: the read every step and every start pays,
     * by primary key. That is the price the verb's completeness costs, accepted by design.
     */
    public function pausedType(string $workflowType): bool;
}
