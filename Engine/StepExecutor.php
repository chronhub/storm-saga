<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Nothing;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Plan\SkipReason;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Event\SagaCancelRefused;
use Storm\Saga\Event\SagaOutcomeDiscarded;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStateRejected;
use Storm\Saga\Exception\WorkflowStateVersionMismatch;
use Storm\Saga\Exception\WorkflowTypePaused;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * The step's shell with zero policy, only the lifecycle: under the fence, in a single unit of
 * work, LOAD the row through the loader's guards, ask the `StepPolicy` for the plan, PERFORM it
 * through the performer's machine and named performers, COMMIT the outcome's row, timers and
 * commands through the committer's invariants, then dispatch the collected `Saga*` announcements
 * after the unit settles. A step that rolled back, or that never got the fence, emits nothing; the
 * voiced no-ops, the refused cancel and the discarded outcome, wrote nothing, so there is no
 * commit to wait for.
 *
 * One step is one transaction: the state advance, its timers, and its outgoing commands commit or
 * roll back together. This is structural, since the machine and the performers return data, never
 * write. `now` is captured once per step and handed to the policy, the machine, and the enforcer as
 * a value.
 *
 * The post-commit dispatch assumes the step's transaction is top-level. When a delivery seam has
 * already opened a transaction on the same connection, such as an inbox consumer wrapping its
 * handlers, the fence's `transactional()` nests as a savepoint: the announcements then fire at the
 * savepoint release, before the outer commit makes the step durable, and the advisory fence stays
 * held until that outer commit {@see \Storm\Saga\Locking\Dbal\PgAdvisoryFence}. Consumers of `Saga*`
 * announcements must tolerate that window; a strict top-level assertion is deliberately not enforced
 * here, so such a seam keeps working.
 *
 * @see StepPolicy the routing table, where every guard and its order lives
 * @see StepLoader the load phase and its guards
 * @see StepPerformer the plan-to-outcome dispatch
 * @see StepCommitter the write point and its invariants
 */
final readonly class StepExecutor
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private SagaStepUnitOfWork $fence,
        private StepLoader $loader,
        private StepPolicy $policy,
        private StepPerformer $performer,
        private StepCommitter $committer,
        private Clock $clock,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * Execute one fenced step for `$signal` and report what happened:
     *
     * - `Applied` when the step changed something: started, advanced, escalated, enforced, settled,
     *   or canceled the saga;
     *
     * - `NothingToDo` on any `Skip`, or a non-event signal that moved nothing; the refused cancel
     *   additionally announces `SagaCancelRefused`, the only voiced skip, since the saga it leaves
     *   behind is alive and someone asked it to die;
     *
     * - `NotYetApplicable` when a delivered event found its Running instance and moved nothing: an
     *   early arrival ahead of its wait, the one no-op whose redelivery WILL help;
     *
     * - `FenceBusy` when a concurrent step holds the fence; the signal was not applied and a retry
     *   will help. This and `NotYetApplicable` are the seams the event router turns into a
     *   retryable throw; Engine's public methods collapse the report to their bool.
     *
     * The definition is resolved from `$registry` by the loaded instance's pinned `definitionVersion`,
     * the latest registered version at birth via `resolve()`, so an in-flight saga always runs the
     * shape it was born under, even after a newer version is deployed.
     *
     * @throws WorkflowNotFound when no version of the workflow type is registered
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws WorkflowTypePaused when a start lands on a paused instance or a paused workflow type
     * @throws WorkflowStateVersionMismatch when the loaded row's state version disagrees with the declaration
     * @throws WorkflowStateRejected when the declared validator refuses the bag about to persist
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable the performer's and the committer's tails, re-thrown
     */
    public function execute(WorkflowRegistry $registry, WorkflowId $id, Signal $signal): ExecutionReport
    {
        return $this->fenced($id, function (PointInTime $now) use ($registry, $id, $signal): StepResult {
            if ($this->loader->timerSignalIsStale($id, $signal, $now)) {
                return StepResult::nothing(); // a straggler's claimed copy: the LIVE timer moved
            }

            $row = $this->loader->find($id);

            // the operator freeze, judged per signal kind BEFORE any plan: the instance stamp or
            // the type registry. The hard cap and the kill switch pass THROUGH: a pause is not
            // immortality and an operator must be able to cancel what an operator froze. Facts
            // ride the retryable channel: redelivered, DLQ at the cap, redrive after the resume;
            // engine-pinned timers arriving here are stragglers the claim no longer delivers, and
            // a user signal is refused as quietly as one on a settled saga.
            if ($signal->kind !== SignalKind::GlobalDeadline && $signal->kind !== SignalKind::Cancel
                && $this->loader->frozen($row, $id->workflowType)) {
                if ($signal->kind === SignalKind::Start) {
                    throw WorkflowTypePaused::forStart($id->workflowType, $id->correlationId);
                }
                if ($row !== null && ($signal->kind === SignalKind::Event || $signal->kind === SignalKind::EffectFailure)) {
                    // the early-event channel: NotYetApplicable, a redelivery WILL help, after the resume
                    return new StepResult(ExecutionReport::NotYetApplicable);
                }

                return StepResult::nothing();
            }

            $def = $this->loader->resolve($registry, $id->workflowType, $row); // pin: latest at birth, the row's version on advance
            $row = $this->loader->migrated($def, $row); // forward when lawful, refused when not; in memory, persisted by the step's write
            $plan = $this->policy->plan($signal, $row, $def, $now);
            $outcome = $this->performer->perform($plan, $def, $id, $row, $now, $signal->causationId);

            if ($outcome instanceof Nothing) {
                return $this->judgedNoOp($def, $id, $signal, $row, $outcome);
            }

            if ($outcome instanceof Created) {
                $born = $this->committer->created($registry, $def, $id, $outcome, $signal);

                // the birth-drive announcements were built on a row whose run number was provisional:
                // the claimed generation is stamped onto every one of them, `claimedAs()`'s twin for
                // the trail
                return new StepResult(ExecutionReport::Applied, announcements: array_map(
                    static fn (SagaAnnouncement $a): SagaAnnouncement => $a->withGeneration($born->generation),
                    $outcome->announcements,
                ));
            }

            if ($outcome instanceof Updated) {
                $this->committer->updated($def, $id, $outcome, $signal);

                return new StepResult(ExecutionReport::Applied, announcements: $outcome->announcements);
            }

            // Effects, an escalation: timers only, the row is untouched
            $this->committer->escalated($id, $outcome);

            return new StepResult(ExecutionReport::Applied, announcements: $outcome->announcements);
        })->report;
    }

    /**
     * The answering signal: one fenced user-signal step whose handler reply is handed back to the
     * caller. The mutation and the answer come from the SAME handler run, synchronous, in-process
     * and under the fence, so there is no signal-then-reread and no race between them. The reply is
     * only ever surfaced from a step that wrote: a rolled-back step reaches neither the update nor
     * this capture, so a refused bag or a thrown handler yields an exception, never a phantom
     * answer.
     *
     * The visibility contract is the caller's transaction: top-level, the reply returns after the
     * commit; under an ambient DBAL transaction the step is a savepoint and the reply exists before
     * the outer commit makes it durable, the same window the announcements tolerate, so the reply
     * is worth exactly what that transaction is worth.
     *
     * @throws Throwable the same contract as {@see execute()}
     */
    public function executeSignalFor(WorkflowRegistry $registry, WorkflowId $id, Signal $signal): SignalReply
    {
        $result = $this->fenced($id, function (PointInTime $now) use ($registry, $id, $signal): StepResult {
            $row = $this->loader->find($id);

            // the operator freeze: an answering signal on a paused saga is refused as quietly as
            // one on a settled saga: NothingToDo, no reply, no phantom answer
            if ($this->loader->frozen($row, $id->workflowType)) {
                return StepResult::nothing();
            }

            $def = $this->loader->resolve($registry, $id->workflowType, $row);
            $row = $this->loader->migrated($def, $row);
            $outcome = $this->performer->perform($this->policy->plan($signal, $row, $def, $now), $def, $id, $row, $now, $signal->causationId);

            if (! $outcome instanceof Updated) {
                return StepResult::nothing();
            }

            $this->committer->updated($def, $id, $outcome, $signal);

            return new StepResult(ExecutionReport::Applied, $outcome->signalReply, $outcome->announcements);
        });

        return new SignalReply($result->report, $result->reply);
    }

    /**
     * The atomic signal-with-start: ONE fence, one transaction, two plans in sequence. Phase one
     * births the instance when no row holds the correlation, exactly as a start would; phase two
     * plans the user signal against the row as this same transaction now sees it, the just-born row
     * or the one that already lived. Between the two there is no window: no competing step can
     * advance the saga past the signal, and neither half commits without the other.
     *
     * The signal only nudges a RUNNING saga, so a birth whose synchronous drive settles the instance
     * never sees it, the policy skipping it like any signal on a settled saga; the start still
     * counts as applied. A signal with no declared handler is dropped as usual, the start alone
     * applying. Announcements from both phases dispatch together after the one commit.
     *
     * @throws Throwable the same contract as {@see execute()}, both phases riding one step
     */
    public function executeStartThenSignal(WorkflowRegistry $registry, WorkflowId $id, Signal $start, Signal $user): ExecutionReport
    {
        return $this->fenced($id, function (PointInTime $now) use ($registry, $id, $start, $user): StepResult {
            $row = $this->loader->find($id);

            // the operator freeze gates both halves: a birth is refused retryable, and the signal
            // half against a paused living row is dropped as quietly as one on a settled saga
            if ($this->loader->frozen($row, $id->workflowType)) {
                if ($row === null) {
                    throw WorkflowTypePaused::forStart($id->workflowType, $id->correlationId);
                }

                return StepResult::nothing();
            }

            $def = $this->loader->resolve($registry, $id->workflowType, $row);
            $row = $this->loader->migrated($def, $row);

            $announcements = [];
            $applied = false;

            if ($row === null) {
                $outcome = $this->performer->perform($this->policy->plan($start, null, $def, $now), $def, $id, null, $now, $start->causationId);
                if ($outcome instanceof Created) {
                    $born = $this->committer->created($registry, $def, $id, $outcome, $start);
                    $announcements = array_map(
                        static fn (SagaAnnouncement $a): SagaAnnouncement => $a->withGeneration($born->generation),
                        $outcome->announcements,
                    );
                    $applied = true;
                    $row = $born;
                }
            }

            $outcome = $this->performer->perform($this->policy->plan($user, $row, $def, $now), $def, $id, $row, $now, $user->causationId);
            if ($outcome instanceof Updated) {
                $this->committer->updated($def, $id, $outcome, $user);
                $announcements = [...$announcements, ...$outcome->announcements];
                $applied = true;
            }

            return $applied
                ? new StepResult(ExecutionReport::Applied, announcements: $announcements)
                : StepResult::nothing();
        })->report;
    }

    /**
     * Migrate ONE instance's stored state to the declared version without running a step: the sweep's
     * write path, and deliberately the same machinery as a step's: the fence, the OCC update, the
     * migration chain, and the declared validator, nothing bespoke. `NothingToDo` when the row is
     * absent or already current, so the sweep's report can count honestly; `FenceBusy` when a live
     * step holds the saga, and the lazy path in `execute()` migrates it anyway.
     *
     * @throws WorkflowStateVersionMismatch ahead of the code, behind with no migrator, or a broken hop
     * @throws WorkflowStateRejected when the migrated bag fails the declared validator
     * @throws StaleWorkflowInstance when a competing step moved the OCC version underneath
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function migrateState(WorkflowRegistry $registry, WorkflowId $id): ExecutionReport
    {
        return $this->fenced($id, function (PointInTime $now) use ($registry, $id): StepResult {
            $row = $this->loader->find($id);
            if ($row === null) {
                return StepResult::nothing();
            }

            $def = $this->loader->resolve($registry, $id->workflowType, $row);
            $migrated = $this->loader->migrated($def, $row);
            if (! $migrated instanceof WorkflowInstanceRow || $migrated === $row) {
                return StepResult::nothing(); // already current; the sweep counts it as nothing to do
            }

            $this->committer->migration($def, $migrated);

            return new StepResult(ExecutionReport::Applied);
        })->report;
    }

    /**
     * The one transaction template every step variant rides: capture `now` once, run the step
     * inside the unit of work, and dispatch AFTER it settles, never before. A rolled-back step
     * never returns a result and so emits nothing; top-level, the settle IS the commit, and under
     * an ambient transaction it is the savepoint release, the window the class docblock documents.
     * The voiced no-ops dispatch whatever the report, since a no-op wrote nothing and has no commit
     * to wait for; the committed announcements dispatch only from an applied step.
     *
     * @param  Closure(PointInTime): StepResult  $step
     *
     * @throws Throwable propagated from the step; the unit restored, nothing dispatched
     */
    private function fenced(WorkflowId $id, Closure $step): StepResult
    {
        $result = StepResult::busy();

        $acquired = $this->fence->tryWithin($id, function () use ($step, &$result): void {
            // one instant per step: policy, machine and enforcer share it. The committer is NOT a
            // sharer: it takes its own instant when arming timers, so a fire instant counts from
            // the commit, not from the policy read; a step is deliberately not a pure function of
            // this one now
            $result = $step($this->clock->now());
        });

        if (! $acquired) {
            return StepResult::busy();
        }

        foreach ($result->voiced as $event) {
            $this->events->dispatch($event);
        }
        if ($result->report === ExecutionReport::Applied) {
            foreach ($result->announcements as $event) {
                $this->events->dispatch($event);
            }
        }

        return $result;
    }

    /**
     * Judge a step that moved nothing. A delivered event that moved nothing on a live instance
     * either arrived ahead of its wait or will never be consumed at all; the definition is what
     * tells the two apart, an unreachable outcome dropped and SAID out loud rather than vanishing
     * into a retry budget. A birth whose declared delay holds the first drive rides the early
     * channel, a redelivery landing once the due has passed. The refused cancel is the one skip
     * with a voice, since the refusal leaves a LIVING saga behind that someone asked to die; every
     * other skip reason is a benign race absorbed quietly.
     */
    private function judgedNoOp(WorkflowDefinition $def, WorkflowId $id, Signal $signal, ?WorkflowInstanceRow $row, Nothing $outcome): StepResult
    {
        if ($outcome->reason === SkipReason::BirthDelayPending) {
            // born, not driven
            return new StepResult(ExecutionReport::NotYetApplicable);
        }

        $unconsumed = $signal->kind === SignalKind::Event
            && $outcome->reason === null
            && $row?->status === WorkflowStatus::Running;

        if ($unconsumed && $signal->event !== null) {
            if ($def->canStillAccept($row->stateKey, $signal->event::class)) {
                return new StepResult(ExecutionReport::NotYetApplicable);
            }

            return new StepResult(ExecutionReport::NeverApplicable, voiced: [
                new SagaOutcomeDiscarded($id->workflowType, $id->correlationId, $row->generation, $row->stateKey, $signal->event::class),
            ]);
        }

        if ($outcome->reason === SkipReason::InFlightEffect && $row !== null) {
            return new StepResult(ExecutionReport::NothingToDo, voiced: [
                new SagaCancelRefused($id->workflowType, $id->correlationId, $row->generation, $row->stateKey, $signal->reason),
            ]);
        }

        return StepResult::nothing();
    }
}
