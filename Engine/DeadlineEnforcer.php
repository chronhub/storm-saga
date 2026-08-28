<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Plan\WaiveGlobalCap;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Event\SagaAwaitOverdue;
use Storm\Saga\Event\SagaCompensationSkipped;
use Storm\Saga\Event\SagaGloballyTimedOut;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * Resolve an elapsed global deadline, the densest business logic of the module, in three branches:
 *
 * - Confirmed rollback: something is safe to undo, a `confirmedBy` event landed, so compensate
 *   location-agnostically and let the rollback supersede `onGlobalTimeout`. This is safe even though
 *   the deadline fires location-agnostically, because `confirmed` is set by the success event
 *   itself, not by position; unverifiable steps are skipped and flagged, the option-A safety
 *   narrowed.
 *
 * - Halt: nothing safe to undo and no `onGlobalTimeout` declared, so freeze on the spot, flagging
 *   any logged-but-unverifiable steps for reconciliation.
 *
 * - Forced routing: drive from `onGlobalTimeout`, which runs, a Final completes or an activity
 *   executes; one machine, two callers.
 *
 * Pure of persistence: returns a Rested run of the row, ordered ops, announcements, and commands;
 * the shell persists and applies. The current state's timers and the consumed global deadline are
 * dropped as leading cancel ops, whatever the branch.
 *
 * `haltAtCap()` is the sibling for the gating case: the cap fell on a non-retriable effect-gating
 * wait, so it halts and flags, a bound and never a finalize or compensate, instead of driving any of
 * the three branches; compensating an in-flight effect there would create money. `waiveAtCap()` is
 * the retriable-gating sibling: it neither finalizes nor halts, but disarms both timers, the spent
 * global and the wait's heartbeat, stamps a durable `waived_at`, and hands off to reconcile with
 * `SagaAwaitOverdue`, so the saga goes quiet instead of re-arming for nothing.
 */
final readonly class DeadlineEnforcer
{
    public function __construct(
        private MachineRunner $machine,
        private Compensator $compensator,
    ) {}

    /**
     * @throws WorkflowStepLimitExceeded when the onGlobalTimeout drive's transition chain cycles
     * @throws UnknownState when `onGlobalTimeout`, or a transition from it, targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws Throwable when any other exception is thrown
     */
    public function enforce(WorkflowDefinition $def, WorkflowInstanceRow $row, PointInTime $now, ?string $causationId): Rested
    {
        $type = $row->workflowType;
        $corr = $row->correlationId;

        // whatever the branch: the current state's timers are moot, the global deadline is consumed
        $cancels = [TimerOp::cancelState($row->stateKey), TimerOp::cancelGlobal()];
        $timedOut = new SagaGloballyTimedOut($type, $corr, $row->generation, $row->stateKey);

        if ($this->compensator->hasConfirmedCompensation($def, $row)) {
            $halted = $row->halted();
            $run = $this->compensator->compensate($def, $halted, new Rested($halted), $causationId, locationAgnostic: true);

            return new Rested($run->row, [$timedOut, ...$run->announcements], [...$cancels, ...$run->timerOps], $run->commands);
        }

        // nothing safe to undo, whether an empty log or only unconfirmable steps: keep the give-up behavior,
        // halt or force to `onGlobalTimeout`, and flag any logged-but-unverifiable steps for reconciliation.
        $skipped = $row->compensations === []
            ? []
            : [new SagaCompensationSkipped($type, $corr, $row->generation, $this->compensator->stepKeys($row->compensations))];

        if ($def->onGlobalTimeout === null) {
            return new Rested($row->halted(), [$timedOut, new SagaHalted($type, $corr, $row->generation, $row->stateKey), ...$skipped], $cancels);
        }

        // drive from the onGlobalTimeout state; it runs: a Final completes, an activity executes, and so on
        $working = $row->forcedTo($def->onGlobalTimeout);
        $run = $this->machine->run($def, $working, Stimulus::none(), $now, $causationId);

        $forced = new SagaTransitioned($type, $corr, $row->generation, $row->stateKey, $def->onGlobalTimeout);
        if (! $run instanceof Rested) {
            // an unmoved force persists the forced row as-is, for example when onGlobalTimeout is a bare untimed wait
            return new Rested($working, [$timedOut, $forced, ...$skipped], $cancels);
        }

        return new Rested($run->row, [$timedOut, $forced, ...$run->announcements, ...$skipped], [...$cancels, ...$run->timerOps], $run->commands);
    }

    /**
     * The global cap fell on a non-retriable effect-gating wait: an effect is in flight that no
     * deadline may finalize, invariant 2, and no compensation may safely undo, since releasing a
     * possibly committed effect means money-loss. Honor the cap as a bound: halt on the spot, flag
     * every logged step for reconciliation, and drive nothing, never the confirmed-rollback or
     * forced-routing branches of `enforce()`. The saga becomes terminal and observable instead of
     * re-arming the global forever; a late outcome event, a dead-letter, or the recon backstop
     * resolves the in-flight leg. A retriable gating wait never reaches here; `StepPolicy` skips the
     * cap for it, so it re-arms forever instead.
     *
     * Pure of persistence, like `enforce()`: returns the halted Rested run for the shell to apply.
     */
    public function haltAtCap(WorkflowInstanceRow $row): Rested
    {
        $type = $row->workflowType;
        $corr = $row->correlationId;

        $cancels = [TimerOp::cancelState($row->stateKey), TimerOp::cancelGlobal()];
        $skipped = $row->compensations === []
            ? []
            : [new SagaCompensationSkipped($type, $corr, $row->generation, $this->compensator->stepKeys($row->compensations))];

        return new Rested(
            $row->halted(),
            [new SagaGloballyTimedOut($type, $corr, $row->generation, $row->stateKey), new SagaHalted($type, $corr, $row->generation, $row->stateKey), ...$skipped],
            $cancels,
        );
    }

    /**
     * The global cap fell on a retriable effect-gating wait: the leg's success is inevitable
     * post-pivot, so the cap must not halt it, invariant 2. But the saga must not keep re-arming for
     * nothing either; a lost, not merely lagging, confirmation would leave the spent global timer
     * re-claiming every lease and the per-wait heartbeat re-pinging every interval, forever. So waive
     * the cap: disarm both timers, the spent global and the wait's heartbeat, stamp `waived_at` on
     * the row as the durable trace, the sweep's input, the straggler guard's read, and the policy's
     * "cap is spent", and emit {@see SagaAwaitOverdue}, the immediate hand-off to reconciliation; the
     * stamped row is its durable backstop when the post-commit announcement is lost. The saga goes
     * quiet, with zero timers, and stays parked, non-terminal: a late outcome still resolves it via
     * the event path. Unlike `haltAtCap()`, which settles the saga, the cap is thus a real bound on
     * the saga's churn, the only bound invariant 2 allows, not on its existence.
     *
     * @see WaiveGlobalCap
     * @see WorkflowInstanceStore::waivedAndQuiet()
     */
    public function waiveAtCap(WorkflowInstanceRow $row, PointInTime $now): Rested
    {
        return new Rested(
            $row->waived($now),
            [new SagaAwaitOverdue($row->workflowType, $row->correlationId, $row->generation, $row->stateKey)],
            [TimerOp::cancelState($row->stateKey), TimerOp::cancelGlobal()],
        );
    }
}
