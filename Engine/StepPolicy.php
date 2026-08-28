<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Engine\Plan\AdvanceInstance;
use Storm\Saga\Engine\Plan\ApplyUserSignal;
use Storm\Saga\Engine\Plan\CancelInstance;
use Storm\Saga\Engine\Plan\EnforceGlobalDeadline;
use Storm\Saga\Engine\Plan\EscalateWait;
use Storm\Saga\Engine\Plan\HaltAtGlobalCap;
use Storm\Saga\Engine\Plan\SettleFailedEffect;
use Storm\Saga\Engine\Plan\Skip;
use Storm\Saga\Engine\Plan\SkipReason;
use Storm\Saga\Engine\Plan\StartInstance;
use Storm\Saga\Engine\Plan\StepPlan;
use Storm\Saga\Engine\Plan\WaiveGlobalCap;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\EffectGating;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The engine's routing table, in one pure place: given what woke the saga, the `Signal`, the loaded
 * instance or none, and the moment, decide what this step is allowed to do, a `StepPlan`. No IO, no
 * clock of its own; `now` is a value. The guard order inside each signal block is the policy: the
 * stale-state guard runs before the gating check, and a delivered event at an effect-gating wait
 * bypasses the deadline guard so a late outcome still resolves the wait.
 *
 * The money-path asymmetries this table encodes:
 *
 *  - Same gating wait, three signals, three plans: an `event` advances and bypasses the deadline
 *    guard, a fired per-state timer escalates and re-arms the heartbeat, the fired global cap halts
 *    via `HaltAtGlobalCap`; a deadline never finalizes an in-flight effect, the cap only bounds it.
 *    The one exception is a `retriable` gating wait, where success is inevitable, post-pivot: the
 *    cap is waived via `WaiveGlobalCap`, disarming both the spent global timer and the wait's
 *    heartbeat, stamping a durable `waived_at` on the row, and handing off to reconcile with
 *    `SagaAwaitOverdue`, so the saga goes quiet instead of re-arming for nothing forever. Post-waive,
 *    a straggler timer claimed before the waive reads the stamp and stays quiet; a bounded gating
 *    wait reached later is capped by the spent deadline via `HaltAtGlobalCap`, never an
 *    escalate-forever.
 *
 *  - An `event` never skips on state, the wake contract: wherever the saga rests, a delivered event
 *    wakes the machine. At a wait it matches, or Noops when unmatched; at a resting activity it
 *    re-executes it, which is the async completion, a wake-and-recheck, and one more re-run source
 *    on top of at-least-once redelivery, so a retried activity is re-entrant by contract and its
 *    back-off spaces the engine's kicks, not external wakes. An early outcome consumed by such a
 *    wake never reaches a later wait: that residual is owned by the wait's own liveness, escalation
 *    to at-rest, never by an engine-side buffer. The wake contract yields once, to time: before a
 *    declared birth delay's due the event skips as `BirthDelayPending` and is reported early, since
 *    waking the undriven start state would run the very effect the delay defers.
 *
 *  - Past the global deadline a non-gating trigger is discarded in favor of `EnforceGlobalDeadline`;
 *    the deadline is authoritative over a racing event.
 *
 *  - `globalDeadline` trusts the fired timer and does not consult the clock, `claimDue` only claims
 *    due timers, while `event`, `stateTimeout`, and `kick` do; the deadline stays enforceable even
 *    if the Global timer row is lost.
 *
 *  - `familyPoke` ignores the deadline for the same reason an event at a gating wait does: it
 *    spends a conclusion the saga ALREADY absorbed, so the deadline has nothing left to arbitrate
 *    against. Its two skips are the ordinary case, since every member's settle pokes and only the
 *    last one finds work: nothing parked, or a park sealed to a wait the saga has left.
 *
 *  - `effectFailure` ignores the deadline entirely, since the safe settle has no expiry window, but
 *    is paired: only the death of the command the wait actually gates settles, its issuing state
 *    gates the resting wait and no same-step sibling is alive. Anything unpaired escalates, never
 *    settles; an unrelated dead command must not discard a healthy in-flight effect.
 */
final readonly class StepPolicy
{
    /**
     * The declared union is the seal: consumers `match (true)` over these ten cases with no default
     * arm, and static analysis proves the match exhaustive; an unknown case can only come from
     * widening this return type, which is a reviewed decision, not an accident.
     *
     * @return StartInstance|AdvanceInstance|EscalateWait|EnforceGlobalDeadline|HaltAtGlobalCap|WaiveGlobalCap|SettleFailedEffect|CancelInstance|ApplyUserSignal|Skip
     */
    public function plan(Signal $signal, ?WorkflowInstanceRow $row, WorkflowDefinition $def, PointInTime $now): StepPlan
    {
        $kind = $signal->kind;

        if ($kind === SignalKind::Start) {
            return $row === null
                ? new StartInstance($signal->vars, $signal->context)
                : new Skip(SkipReason::AlreadyStarted); // idempotent: settled or not, it exists
        }

        if ($row === null) {
            return new Skip(SkipReason::NotFound);
        }
        if ($row->status !== WorkflowStatus::Running) {
            return new Skip(SkipReason::AlreadySettled);
        }

        return match ($kind) {
            SignalKind::Event => $this->onEvent($signal, $row, $def, $now),
            SignalKind::StateTimeout => $this->onStateTimeout($signal, $row, $def, $now),
            SignalKind::Kick => $this->onKick($signal, $row, $def, $now),
            SignalKind::Schedule => $this->onSchedule($signal, $row),
            SignalKind::GlobalDeadline => $this->onGlobalDeadline($row, $def),
            SignalKind::EffectFailure => $this->onEffectFailure($signal, $row, $def),
            SignalKind::Cancel => $this->onCancel($signal, $row, $def),
            // a user signal nudges the LIVE saga: handler declared for the object's class, then apply,
            // moving vars with no transition; none, then drop WITH a reason: no buffering,
            // the same drop the wake contract gives an unmatched event, but observable
            SignalKind::User => ($userSignal = $signal->event) !== null && $def->signalHandlerFor($userSignal) !== null
                ? new ApplyUserSignal($userSignal)
                : new Skip(SkipReason::NoSignalHandler),
            SignalKind::FamilyPoke => $this->onFamilyPoke($row),
            // No default arm; exhaustive over SignalKind, an unhandled kind raises a loud UnhandledMatchError.
            // A PINNED signal, timeout/kick/schedule, that is valid but unexpected in the current STATE is
            // deliberately NOT an error: in an at-least-once engine, with re-armed timers or a signal losing the
            // race to a transition, it is a benign race, returned as a Skip(SkipReason) and collapsed to the
            // public false: nameable in tests, quiet by design, never a thrown DLQ. The ONE voiced skip is the
            // refused cancel, announced by the executor as SagaCancelRefused: it alone leaves a living saga
            // behind that someone asked to die. An EVENT is the deliberate exception: it never skips on state,
            // the wake contract; its one skip, BirthDelayPending, is on time, not state, and the executor
            // reports it early for redelivery instead of collapsing it.
        };
    }

    /**
     * A spawned member of an indexed family settled: spend the crossing the family gate rested, if
     * one is owed HERE. The park names the wait it was taken at, so a saga that has since moved on
     * reads its own park as stale rather than replaying a crossing at a state that never rested it.
     *
     * The deadline is deliberately not consulted, on the same ground a delivered event at a gating
     * wait bypasses it: the crossing being replayed is a fact that ALREADY arrived and was absorbed,
     * and refusing it now would finalize a saga on a conclusion it holds but has not applied.
     * Completeness is judged in the performer, which has the store; here there is only the row.
     */
    private function onFamilyPoke(WorkflowInstanceRow $row): Skip|AdvanceInstance
    {
        $parked = $row->parked;

        if ($parked === null) {
            return new Skip(SkipReason::NothingParked);
        }
        if ($parked['state'] !== $row->stateKey) {
            return new Skip(SkipReason::StaleState);
        }

        return new AdvanceInstance(Stimulus::replay($parked['event']));
    }

    private function onEvent(Signal $signal, WorkflowInstanceRow $row, WorkflowDefinition $def, PointInTime $now): Skip|AdvanceInstance|EnforceGlobalDeadline
    {
        /** @var object $event Signal::event() is the only way to build an Event signal */
        $event = $signal->event;

        if ($this->beforeBirthDue($def, $row, $now)) {
            // before the first drive no effect of this run is in flight, so no gating-wait clause
            // can outrank the delay; the executor reports this skip early, never a silent false
            return new Skip(SkipReason::BirthDelayPending);
        }
        if ($this->gatingWait($def, $row->stateKey)) {
            // bypasses the deadline guard: a late outcome must still resolve the wait, invariant 2
            return new AdvanceInstance(Stimulus::event($event));
        }
        if ($this->pastGlobalDeadline($def, $row, $now)) {
            return new EnforceGlobalDeadline; // the event is discarded; the deadline is authoritative, invariant 3
        }

        return new AdvanceInstance(Stimulus::event($event));
    }

    private function onStateTimeout(Signal $signal, WorkflowInstanceRow $row, WorkflowDefinition $def, PointInTime $now): Skip|EscalateWait|EnforceGlobalDeadline|AdvanceInstance|HaltAtGlobalCap
    {
        if ($signal->expectedStateKey !== null && $row->stateKey !== $signal->expectedStateKey) {
            return new Skip(SkipReason::StaleState); // the timer lost its race against a transition
        }
        if ($this->gatingWait($def, $row->stateKey)) {
            if ($row->waivedAt !== null) {
                // the cap was WAIVED, spent, durable on the row. At the retriable wait itself a straggler
                // heartbeat, claimed before the waive, stays quiet; re-arming would resurrect the churn the
                // waive killed. A BOUNDED gating wait reached after the waive has no global timer left to
                // bound it: the spent cap bounds it here via HaltAtGlobalCap, never an escalate-forever.
                return $this->retriableWait($def, $row->stateKey)
                    ? new Skip(SkipReason::CapWaived)
                    : new HaltAtGlobalCap;
            }

            return new EscalateWait; // liveness: re-arm the wait's own timeout, never finalize, invariant 2
        }
        if ($this->pastGlobalDeadline($def, $row, $now)) {
            return new EnforceGlobalDeadline;
        }

        return new AdvanceInstance(Stimulus::timeout());
    }

    /**
     * A schedule state's slot came due. The stale-state guard mirrors a timeout's, since a re-armed
     * cadence timer that lost its race against a transition is a no-op; otherwise drive the machine
     * with the fired slot. There is no deadline branch, because a schedule workflow has no
     * globalTimeout, rejected at build.
     */
    private function onSchedule(Signal $signal, WorkflowInstanceRow $row): Skip|AdvanceInstance
    {
        if ($signal->expectedStateKey !== null && $row->stateKey !== $signal->expectedStateKey) {
            return new Skip(SkipReason::StaleState);
        }

        /** @var PointInTime $dueAt Signal::schedule() always carries it */
        $dueAt = $signal->scheduleDueAt;

        return new AdvanceInstance(Stimulus::schedule($dueAt));
    }

    private function onKick(Signal $signal, WorkflowInstanceRow $row, WorkflowDefinition $def, PointInTime $now): Skip|AdvanceInstance|EnforceGlobalDeadline
    {
        if ($signal->expectedStateKey !== null && $row->stateKey !== $signal->expectedStateKey) {
            return new Skip(SkipReason::StaleState);
        }
        if ($this->gatingWait($def, $row->stateKey)) {
            // gating and not-a-timeout falls through to the machine, in practice unreachable: a kick
            // pins an activity state, a gating wait is a WaitState, the stale guard catches it first
            return new AdvanceInstance(Stimulus::none());
        }
        if ($this->pastGlobalDeadline($def, $row, $now)) {
            return new EnforceGlobalDeadline;
        }

        return new AdvanceInstance(Stimulus::none()); // re-run the current state, the back-off retry
    }

    private function onGlobalDeadline(WorkflowInstanceRow $row, WorkflowDefinition $def): Skip|WaiveGlobalCap|HaltAtGlobalCap|EnforceGlobalDeadline
    {
        if ($def->globalTimeout === null) {
            return new Skip(SkipReason::NoGlobalDeadline); // a phantom timer; the definition changed
        }
        if ($row->waivedAt !== null) {
            // a straggler Global timer, claimed before the waive committed: the cap is already spent;
            // waiving twice would re-announce and re-write for nothing
            return new Skip(SkipReason::CapWaived);
        }
        if ($this->gatingWait($def, $row->stateKey)) {
            if ($this->retriableWait($def, $row->stateKey)) {
                // a retriable gating wait must NOT be halted by the cap, its effect is in flight, invariant 2; but
                // it must not re-arm for nothing either: at the cap the engine WAIVES it via waiveAtCap, then disarm
                // BOTH the spent global timer and the wait's heartbeat, emit SagaAwaitOverdue, handing off to
                // reconciliation, and go quiet. pastGlobalDeadline, the clock, still bounds any later
                // non-gating signal, so the deadline holds without the timer rows; a late outcome still resolves.
                return new WaiveGlobalCap;
            }

            // the cap bounds the saga, it never finalizes an in-flight effect, invariant 2: halt and flag for
            // reconciliation, do NOT re-arm forever; onGlobalTimeout is unreachable through a gating wait
            return new HaltAtGlobalCap;
        }

        return new EnforceGlobalDeadline; // trusts the fired timer; no clock consult
    }

    /**
     * The settle is paired: it fires only when the dead command is the one the resting wait gates,
     * its issuing state gates this wait, and no other command of the same step is still alive, the
     * multi-command ambiguity. Anything unpaired must never settle, since compensating around a
     * healthy in-flight effect would create money; it escalates instead, visibly so liveness and
     * at-rest resolve, except at a waived saga, which stays quiet by design. Unpaired means one of:
     *
     * - No provenance, an unknown id or a pre-upgrade row;
     * - A foreign issuing state, where an unrelated fire-and-forget died while the gated effect is
     *   healthy in flight;
     * - A living sibling.
     *
     * A fourth way to stand down is the one that is not about WHICH command died: an effect whose
     * non-commit nobody proved, per {@see EffectEvidence}.
     */
    private function onEffectFailure(Signal $signal, WorkflowInstanceRow $row, WorkflowDefinition $def): Skip|SettleFailedEffect|EscalateWait
    {
        if (! $this->gatingWait($def, $row->stateKey)) {
            return new Skip(SkipReason::PastGatingWait); // the outcome arrived another way, or the saga moved on
        }

        $provenance = $signal->provenance;
        $paired = $provenance !== null
            && EffectGating::gatedBy($def->states(), $row->stateKey, $provenance->issuedFromState)
            && ! $provenance->hasAliveSiblings;

        // Pairing says WHICH command died; the evidence says whether its effect can have landed. Both
        // must hold: a paired command whose handler may have committed is exactly the case where
        // compensating creates the money it meant to protect.
        if (! $paired || $provenance->evidence !== EffectEvidence::Uncommitted) {
            return $row->waivedAt !== null
                ? new Skip(SkipReason::CapWaived) // quiet by design; re-arming would resurrect the churn
                : new EscalateWait;
        }

        return new SettleFailedEffect; // ignores the deadline; the safe settle has no expiry, invariant 5
    }

    private function onCancel(Signal $signal, WorkflowInstanceRow $row, WorkflowDefinition $def): Skip|CancelInstance
    {
        if (! $signal->force && $this->gatingWait($def, $row->stateKey)) {
            // an in-flight effect is never discarded on an operator's word alone, invariant 2:
            // retry after the outcome lands, or own the risk explicitly with --force. The executor
            // announces this refusal as SagaCancelRefused; the saga survives, visibly
            return new Skip(SkipReason::InFlightEffect);
        }

        return new CancelInstance($signal->reason);
    }

    /**
     * Whether the saga is parked in an effect-gating wait, a WaitState that is the success-target of
     * an activity; the predicate is shared with the build-time guard via EffectGating.
     */
    private function gatingWait(WorkflowDefinition $def, string $stateKey): bool
    {
        return $def->hasState($stateKey)
            && $def->state($stateKey) instanceof WaitState
            && EffectGating::gates($def->states(), $stateKey);
    }

    /**
     * Whether the wait is `retriable`, a heartbeat wait, gating by build rule, whose success is
     * inevitable post-pivot, so the global cap must not halt it. Consulted only inside the gating
     * branch of `onGlobalDeadline()`.
     */
    private function retriableWait(WorkflowDefinition $def, string $stateKey): bool
    {
        $state = $def->hasState($stateKey) ? $def->state($stateKey) : null;

        return $state instanceof WaitState && $state->retriable;
    }

    private function pastGlobalDeadline(WorkflowDefinition $def, WorkflowInstanceRow $row, PointInTime $now): bool
    {
        return $def->globalTimeout !== null
            && $row->startedAt !== null
            && $row->startedAt->addSeconds(max(1, $def->globalTimeout))->isBefore($now);
    }

    /**
     * Whether the saga still rests inside its declared birth delay: `startAfterSeconds` declared,
     * the row at the undriven start state, the due not yet reached. The window is derived, never
     * stored: `startedAt` is the immutable birth anchor and the due is `startedAt` plus the
     * declared delay, the same arithmetic that anchors the global deadline. Scoped to the start
     * state and bounded by the due, so a saga resting elsewhere is never gated and a later return
     * to the start state falls past a due that has elapsed forever.
     *
     * @infection-ignore-all; equivalent: the `max(1, …)` below restates the floor the build rule
     * already enforces on a birth delay, so raising or lowering it changes nothing. Safe at method
     * scope: this is the only mutated line in the file, so nothing killed is masked.
     */
    private function beforeBirthDue(WorkflowDefinition $def, WorkflowInstanceRow $row, PointInTime $now): bool
    {
        return $def->startAfterSeconds !== null
            && $row->stateKey === $def->start
            && $row->startedAt !== null
            // the max(1, …) restates the build rule's own floor on a birth delay; addSeconds requires
            // a positive int and this is where that is made true
            && $now->isBefore($row->startedAt->addSeconds(max(1, $def->startAfterSeconds)));
    }
}
