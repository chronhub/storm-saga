<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
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
use Storm\Saga\Engine\Plan\WaiveGlobalCap;
use Storm\Saga\Engine\Signal;
use Storm\Saga\Engine\StepPolicy;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\SignalResult;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The routing table, exhaustively: one test per cell of the policy, signal kind against instance
 * situation, plus one per money-path asymmetry. The executable documentation of the engine's entry
 * behavior, over three fixture states:
 *
 * - `await` is an effect-gating wait, the success-target of `charge`;
 * - `retry_await` is a retriable effect-gating wait, the one the cap waives;
 * - `lobby` is a plain event-only wait, NOT gating.
 */
final class StepPolicyTest extends TestCase
{
    private StepPolicy $policy;

    private PointInTime $startedAt;

    #[Override]
    protected function setUp(): void
    {
        $this->policy = new StepPolicy;
        $this->startedAt = new PointInTime;
    }

    // -------------------------------------------------------------- start

    #[Test]
    public function a_start_plans_a_fresh_instance_when_none_exists(): void
    {
        $plan = $this->policy->plan(Signal::start(['a' => 1], ['actor' => 'cli']), null, $this->def(), $this->now());

        $this->assertInstanceOf(StartInstance::class, $plan);
        $this->assertSame(['a' => 1], $plan->vars);
        $this->assertSame(['actor' => 'cli'], $plan->context);
    }

    #[Test]
    public function a_second_start_skips_as_already_started(): void
    {
        $plan = $this->policy->plan(Signal::start(), $this->running('charge'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::AlreadyStarted, $plan);
    }

    #[Test]
    public function a_start_on_a_settled_instance_also_skips_as_already_started(): void
    {
        // settled or not, the instance exists and start is idempotent, not AlreadySettled
        $plan = $this->policy->plan(Signal::start(), $this->settled('done'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::AlreadyStarted, $plan);
    }

    // -------------------------------------------------- shared row guards

    #[Test]
    public function any_non_start_signal_skips_when_no_instance_exists(): void
    {
        foreach ($this->nonStartSignals() as $label => $signal) {
            $plan = $this->policy->plan($signal, null, $this->def(), $this->now());

            $this->assertSkip(SkipReason::NotFound, $plan, $label);
        }
    }

    #[Test]
    public function any_non_start_signal_skips_on_a_settled_instance(): void
    {
        foreach ($this->nonStartSignals() as $label => $signal) {
            $plan = $this->policy->plan($signal, $this->settled('lobby'), $this->def(), $this->now());

            $this->assertSkip(SkipReason::AlreadySettled, $plan, $label);
        }
    }

    // -------------------------------------------------------------- event

    #[Test]
    public function an_event_advances_a_running_instance(): void
    {
        $event = new SampleEvent;
        $plan = $this->policy->plan(Signal::event($event), $this->running('lobby'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertSame($event, $plan->stimulus->eventOrNull());
        $this->assertFalse($plan->stimulus->isTimeout());
    }

    #[Test]
    public function an_event_at_a_gating_wait_advances_even_past_the_global_deadline(): void
    {
        // invariant 2: the late outcome must still resolve the wait; the deadline guard is bypassed
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('await'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    public function an_event_past_the_deadline_elsewhere_is_discarded_for_the_enforcement(): void
    {
        // invariant 3: the deadline is authoritative; the event is dropped, the deadline enforced
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(EnforceGlobalDeadline::class, $plan);
    }

    #[Test]
    public function an_event_exactly_at_the_deadline_instant_is_not_past_it(): void
    {
        // boundary: startedAt + globalTimeout == now means NOT past; isBefore is strict
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $this->def(), $this->startedAt->addSeconds(60));

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    public function the_deadline_floor_is_one_second(): void
    {
        // a zero-second globalTimeout is floored to 1s: one second after start is AT the deadline, not past
        $def = $this->def(globalTimeout: 0);
        $atFloor = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $def, $this->startedAt->addSeconds(1));
        $pastFloor = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $def, $this->startedAt->addSeconds(2));

        $this->assertInstanceOf(AdvanceInstance::class, $atFloor);
        $this->assertInstanceOf(EnforceGlobalDeadline::class, $pastFloor);
    }

    #[Test]
    public function without_a_declared_global_timeout_a_late_event_still_advances(): void
    {
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $this->def(globalTimeout: null), $this->startedAt->addSeconds(999_999));

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_event_at_a_resting_activity_state_advances_never_skips(): void
    {
        // THE WAKE CONTRACT: onEvent is the ONLY handler with no Skip arm; an
        // event never skips on state. Delivered to a row resting on an ACTIVITY, a retry back-off or
        // an async stay, the plan advances and the machine RE-EXECUTES the activity, the async
        // completion path, and one more re-run source on top of at-least-once redelivery. A Skip
        // introduced here would break async completion.
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('charge'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    // ------------------------------------------------------ state timeout

    #[Test]
    public function a_state_timeout_advances_with_a_timeout_stimulus(): void
    {
        $plan = $this->policy->plan(Signal::stateTimeout('lobby'), $this->running('lobby'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertTrue($plan->stimulus->isTimeout());
        $this->assertNull($plan->stimulus->eventOrNull());
    }

    #[Test]
    public function a_stale_state_timeout_skips(): void
    {
        // the timer was armed for `lobby` but the saga moved on; the timer lost its race
        $plan = $this->policy->plan(Signal::stateTimeout('lobby'), $this->running('charge'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::StaleState, $plan);
    }

    #[Test]
    public function a_state_timeout_without_a_pinned_state_carries_no_stale_guard(): void
    {
        // a null expectedStateKey = the caller doesn't pin a state, an unpinned timeout signal; no stale skip
        $plan = $this->policy->plan(Signal::stateTimeout(null), $this->running('lobby'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertTrue($plan->stimulus->isTimeout());
    }

    #[Test]
    public function a_state_timeout_at_a_gating_wait_escalates(): void
    {
        // invariant 2: a deadline never finalizes an in-flight effect; re-arm the wait's own timer
        $plan = $this->policy->plan(Signal::stateTimeout('await'), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    public function the_stale_guard_runs_before_the_gating_check(): void
    {
        // order pin: a stale timer aimed at another state skips even though the saga sits at a gating wait
        $plan = $this->policy->plan(Signal::stateTimeout('lobby'), $this->running('await'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::StaleState, $plan);
    }

    #[Test]
    public function a_state_timeout_past_the_deadline_enforces_it(): void
    {
        $plan = $this->policy->plan(Signal::stateTimeout('lobby'), $this->running('lobby'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(EnforceGlobalDeadline::class, $plan);
    }

    // --------------------------------------------------------------- kick

    #[Test]
    public function a_kick_reruns_the_state_with_no_stimulus(): void
    {
        $plan = $this->policy->plan(Signal::kick('charge'), $this->running('charge'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertNull($plan->stimulus->eventOrNull());
        $this->assertFalse($plan->stimulus->isTimeout());
    }

    #[Test]
    public function a_stale_kick_skips(): void
    {
        $plan = $this->policy->plan(Signal::kick('charge'), $this->running('lobby'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::StaleState, $plan);
    }

    #[Test]
    public function a_kick_at_a_gating_wait_falls_through_to_the_machine_not_the_escalation(): void
    {
        // the rule as written: gating and not-a-timeout routes to the machine. In practice unreachable, a kick
        // pins an activity state, but the table doesn't depend on that external reasoning.
        $plan = $this->policy->plan(Signal::kick('await'), $this->running('await'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertFalse($plan->stimulus->isTimeout());
    }

    #[Test]
    public function a_kick_past_the_deadline_enforces_it(): void
    {
        $plan = $this->policy->plan(Signal::kick('lobby'), $this->running('lobby'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(EnforceGlobalDeadline::class, $plan);
    }

    // ----------------------------------------------------------- schedule

    #[Test]
    public function a_schedule_slot_advances_carrying_the_due_instant(): void
    {
        $dueAt = $this->startedAt->addSeconds(10);
        $plan = $this->policy->plan(Signal::schedule('lobby', $dueAt), $this->running('lobby'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertSame($dueAt->toString(), $plan->stimulus->scheduleDueAtOrNull()?->toString());
        $this->assertFalse($plan->stimulus->isTimeout());
    }

    #[Test]
    public function a_stale_schedule_skips(): void
    {
        // the cadence timer was armed for `lobby` but the saga moved on; no deadline branch, a schedule
        // workflow has no globalTimeout, so the stale guard is the only gate
        $plan = $this->policy->plan(Signal::schedule('lobby', $this->startedAt), $this->running('charge'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::StaleState, $plan);
    }

    // ---------------------------------------------------- global deadline

    #[Test]
    public function the_global_deadline_trusts_the_fired_timer_and_does_not_consult_the_clock(): void
    {
        // `now` is well BEFORE startedAt + globalTimeout, yet the fired Global timer is enforced as-is.
        // claimDue only claims due timers; the store guarantees it, the policy doesn't re-check.
        $plan = $this->policy->plan(Signal::globalDeadline(), $this->running('lobby'), $this->def(), $this->startedAt->addSeconds(1));

        $this->assertInstanceOf(EnforceGlobalDeadline::class, $plan);
    }

    #[Test]
    public function a_global_deadline_without_a_declared_timeout_skips_as_a_phantom(): void
    {
        $plan = $this->policy->plan(Signal::globalDeadline(), $this->running('lobby'), $this->def(globalTimeout: null), $this->now());

        $this->assertSkip(SkipReason::NoGlobalDeadline, $plan);
    }

    #[Test]
    public function the_global_deadline_at_a_gating_wait_halts_at_the_cap(): void
    {
        // the cap bounds the saga; it never finalizes/compensates an in-flight effect: halt + flag
        $plan = $this->policy->plan(Signal::globalDeadline(), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(HaltAtGlobalCap::class, $plan);
    }

    // ----------------------------------------------------- effect failure

    #[Test]
    public function a_failed_effect_settles_at_the_gating_wait_it_is_paired_to(): void
    {
        // paired: the dead command was issued by 'charge', whose success gates 'await', and no other
        // command of that step is still alive. The settle is safe: THE awaited outcome can never come.
        $paired = new EffectProvenance('charge', hasAliveSiblings: false, evidence: EffectEvidence::Uncommitted);

        $plan = $this->policy->plan(Signal::effectFailure(failedMessageId: 'mid-1', provenance: $paired), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(SettleFailedEffect::class, $plan);
    }

    #[Test]
    public function a_failed_effect_skips_when_the_saga_is_past_its_gating_wait(): void
    {
        $plan = $this->policy->plan(Signal::effectFailure(), $this->running('lobby'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::PastGatingWait, $plan);
    }

    #[Test]
    public function a_failed_effect_ignores_the_global_deadline(): void
    {
        // invariant 5: the safe settle has no expiry window; way past the deadline it still settles
        $paired = new EffectProvenance('charge', hasAliveSiblings: false, evidence: EffectEvidence::Uncommitted);

        $plan = $this->policy->plan(Signal::effectFailure(failedMessageId: 'mid-1', provenance: $paired), $this->running('await'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(SettleFailedEffect::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_paired_failed_effect_whose_effect_is_unproven_escalates_instead_of_settling(): void
    {
        // pairing says WHICH command died; the evidence says whether its effect can have landed. This one
        // is paired perfectly, right issuing state, no living sibling, and still must not compensate:
        // the handler ran and nobody proved the throw took its writes with it. Rolling back around an
        // effect that DID land is how money is created, so the saga escalates and stays visible instead.
        $unproven = new EffectProvenance('charge', hasAliveSiblings: false, evidence: EffectEvidence::Unknown);

        $plan = $this->policy->plan(Signal::effectFailure(failedMessageId: 'mid-1', provenance: $unproven), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unpaired_failed_effect_escalates_instead_of_settling(): void
    {
        // no provenance, unknown id, a pre-upgrade row: the engine cannot prove the dead command is the
        // one this wait gates; settling could compensate around a HEALTHY in-flight effect, creating money.
        // It escalates instead: visible, and the wait's own liveness/at-rest resolves.
        $plan = $this->policy->plan(Signal::effectFailure(), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_dead_command_from_a_foreign_state_never_settles_the_wait(): void
    {
        // an unrelated command, a fire-and-forget issued by an earlier step, dead-letters while the
        // gated effect is healthy in flight; 'recharge' does not gate 'await', so no settle.
        $foreign = new EffectProvenance('recharge', hasAliveSiblings: false);

        $plan = $this->policy->plan(Signal::effectFailure(failedMessageId: 'mid-x', provenance: $foreign), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_dead_command_with_a_living_same_step_sibling_never_settles(): void
    {
        // the multi-command ambiguity: the issuing step also dispatched a sibling that is still alive;
        // the engine cannot know WHICH command the wait gates, so it stands down: escalate, and at-rest resolves
        $ambiguous = new EffectProvenance('charge', hasAliveSiblings: true);

        $plan = $this->policy->plan(Signal::effectFailure(failedMessageId: 'mid-1', provenance: $ambiguous), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unpaired_failed_effect_at_a_waived_saga_stays_quiet(): void
    {
        // a waived saga is quiet by design; an unpaired signal must not resurrect the heartbeat
        $row = $this->running('retry_await')->waived($this->now());

        $plan = $this->policy->plan(Signal::effectFailure(), $row, $this->def(), $this->now());

        $this->assertSkip(SkipReason::CapWaived, $plan);
    }

    // ------------------------------------------------------------ cancel

    #[Test]
    public function a_cancel_on_a_plain_state_plans_the_cancellation(): void
    {
        $plan = $this->policy->plan(Signal::cancel('duplicate order'), $this->running('lobby'), $this->def(), $this->now());

        $this->assertInstanceOf(CancelInstance::class, $plan);
        $this->assertSame('duplicate order', $plan->reason);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_cancel_at_a_gating_wait_without_force_is_refused(): void
    {
        // inv. 2 holds against the OPERATOR too: an in-flight effect is never discarded on a word alone
        $plan = $this->policy->plan(Signal::cancel('ops'), $this->running('await'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::InFlightEffect, $plan);
    }

    #[Test]
    public function a_forced_cancel_at_a_gating_wait_plans_owning_the_risk(): void
    {
        $plan = $this->policy->plan(Signal::cancel('ops', force: true), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(CancelInstance::class, $plan);
        $this->assertSame('ops', $plan->reason);
    }

    #[Test]
    public function force_is_opt_in_on_every_signal(): void
    {
        // the value contract: no signal carries force unless the cancel factory was told to
        $this->assertFalse(Signal::cancel()->force);
        $this->assertFalse(Signal::event(new SampleEvent)->force);
        $this->assertNull(Signal::cancel()->reason);
    }

    // -------------------------------------------- gating predicate edges

    #[Test]
    public function an_activity_state_is_never_a_gating_wait(): void
    {
        // gating = a WaitState that is the success-target of an activity; an activity state never gates
        $plan = $this->policy->plan(Signal::stateTimeout('charge'), $this->running('charge'), $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertTrue($plan->stimulus->isTimeout());
    }

    #[Test]
    public function an_unknown_state_key_is_not_gating_and_falls_through_the_guards(): void
    {
        // a row whose state left the definition, an in-flight versioning drift: not gating here. The
        // machine will throw UnknownState when it actually runs; past the deadline, enforcement wins
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('ghost'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(EnforceGlobalDeadline::class, $plan);
    }

    // -------------------------------------------------------- retriable wait

    #[Test]
    #[Group('adversarial')]
    public function a_straggler_heartbeat_at_a_waived_retriable_wait_stays_quiet(): void
    {
        // a heartbeat CLAIMED before the waive committed can still drive its in-memory copy, the waive's
        // cancel deleted the row, not the claim: re-arming here would resurrect the exact churn the waive
        // killed; the durable stamp keeps the straggler quiet
        $row = $this->running('retry_await')->waived($this->now());

        $plan = $this->policy->plan(Signal::stateTimeout('retry_await'), $row, $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::CapWaived, $plan->reason);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_bounded_gating_wait_reached_after_the_waive_is_capped_not_escalated_forever(): void
    {
        // post-waive there is NO global timer left to bound a later BOUNDED gating wait; without the
        // stamp its heartbeat would escalate forever, the exact hole HaltAtGlobalCap closes; the spent
        // cap bounds it here instead
        $row = $this->running('await')->waived($this->now());

        $plan = $this->policy->plan(Signal::stateTimeout('await'), $row, $this->def(), $this->now());

        $this->assertInstanceOf(HaltAtGlobalCap::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_straggler_global_timer_at_a_waived_saga_skips(): void
    {
        // a Global timer claimed before the waive committed: the cap is already spent; waiving twice
        // would re-announce and re-write for nothing
        $row = $this->running('retry_await')->waived($this->now());

        $plan = $this->policy->plan(Signal::globalDeadline(), $row, $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::CapWaived, $plan->reason);
    }

    #[Test]
    public function the_global_deadline_at_a_retriable_gating_wait_is_waived(): void
    {
        // a retriable gating wait's success is inevitable: the cap does NOT halt it; the heartbeat
        // re-arms forever. The spent one-shot global timer is WAIVED, disarmed, never left to re-claim every
        // lease forever; distinct from a Skip, which would leave the timer armed. By contrast `await` halts.
        $plan = $this->policy->plan(Signal::globalDeadline(), $this->running('retry_await'), $this->def(), $this->now());

        $this->assertInstanceOf(WaiveGlobalCap::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_state_timeout_at_a_retriable_wait_still_escalates(): void
    {
        // retriable changes ONLY the global-cap routing; the per-state heartbeat path is unchanged, a re-arm
        $plan = $this->policy->plan(Signal::stateTimeout('retry_await'), $this->running('retry_await'), $this->def(), $this->now());

        $this->assertInstanceOf(EscalateWait::class, $plan);
    }

    #[Test]
    public function an_event_at_a_retriable_wait_past_the_deadline_still_advances(): void
    {
        // retry-forward-until-success: the late outcome resolves the retriable wait even past the cap
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('retry_await'), $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    // -------------------------------------------------------- birth delay

    #[Test]
    #[Group('adversarial')]
    public function an_event_before_the_birth_due_is_held_as_birth_delay_pending(): void
    {
        // the wake contract yields to the declared delay: waking the undriven start state would
        // run the very effect the delay defers
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('charge'), $this->defDeferred(), $this->now());

        $this->assertSkip(SkipReason::BirthDelayPending, $plan);
    }

    #[Test]
    public function an_event_at_the_birth_due_advances(): void
    {
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('charge'), $this->defDeferred(), $this->startedAt->addSeconds(30));

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    public function an_event_away_from_the_start_state_ignores_the_birth_delay(): void
    {
        // the window is the undriven start state alone; the rest of the graph keeps the wake contract
        $plan = $this->policy->plan(Signal::event(new SampleEvent), $this->running('lobby'), $this->defDeferred(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    public function a_cancel_during_the_birth_delay_still_cancels(): void
    {
        // a pause is not immortality and neither is a delay: the operator's cancel passes through
        $plan = $this->policy->plan(Signal::cancel(), $this->running('charge'), $this->defDeferred(), $this->now());

        $this->assertInstanceOf(CancelInstance::class, $plan);
    }

    // ------------------------------------------------------------ helpers

    private function assertSkip(SkipReason $reason, object $plan, string $label = ''): void
    {
        $this->assertInstanceOf(Skip::class, $plan, $label);
        $this->assertSame($reason, $plan->reason, $label);
    }

    /**
     * @return array<string, Signal>
     */
    private function nonStartSignals(): array
    {
        return [
            'event' => Signal::event(new SampleEvent),
            'stateTimeout' => Signal::stateTimeout('lobby'),
            'kick' => Signal::kick('lobby'),
            'schedule' => Signal::schedule('lobby', $this->startedAt),
            'globalDeadline' => Signal::globalDeadline(),
            'effectFailure' => Signal::effectFailure(),
            'cancel' => Signal::cancel(),
        ];
    }

    /**
     * The fixture graph:
     *
     * - The `charge` activity succeeds into `await`, the gating wait, which consumes its event into
     *   `done`;
     *
     * - The `recharge` activity succeeds into `retry_await`, a retriable gating wait, which consumes
     *   its event into `done`;
     *
     * - `lobby` is a plain event-only wait, the success-target of nothing and therefore not gating.
     */
    #[Test]
    public function a_family_poke_replays_the_crossing_the_row_parked(): void
    {
        $row = $this->parkedAt('await', ['state' => 'await', 'event' => SampleEvent::class, 'cause' => 'cause-1']);

        $plan = $this->policy->plan(Signal::familyPoke(), $row, $this->def(), $this->now());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
        $this->assertSame(SampleEvent::class, $plan->stimulus->replayedEventClassOrNull());
        $this->assertNull($plan->stimulus->eventOrNull()); // a class, never an object
    }

    #[Test]
    public function a_family_poke_at_a_row_that_parked_nothing_skips(): void
    {
        // the ordinary answer: every member's settle pokes and only the last one can find work
        $plan = $this->policy->plan(Signal::familyPoke(), $this->running('await'), $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::NothingParked, $plan->reason);
    }

    #[Test]
    public function a_family_poke_at_a_row_that_left_the_wait_it_parked_at_skips(): void
    {
        // the park is SEALED to its wait, so a saga that crossed and came back reads its own stale
        // park as stale rather than replaying a crossing at a state that never rested one
        $row = $this->parkedAt('lobby', ['state' => 'await', 'event' => SampleEvent::class, 'cause' => null]);

        $plan = $this->policy->plan(Signal::familyPoke(), $row, $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::StaleState, $plan->reason);
    }

    #[Test]
    public function a_family_poke_past_the_global_deadline_still_replays(): void
    {
        // the same ground an event at a gating wait bypasses the deadline on: the crossing being
        // replayed is a fact that ALREADY arrived and was absorbed, so the deadline has nothing left
        // to arbitrate against and finalizing here would strand a conclusion the saga holds
        $row = $this->parkedAt('lobby', ['state' => 'lobby', 'event' => SampleEvent::class, 'cause' => null]);

        $plan = $this->policy->plan(Signal::familyPoke(), $row, $this->def(), $this->wayPastDeadline());

        $this->assertInstanceOf(AdvanceInstance::class, $plan);
    }

    #[Test]
    public function a_family_poke_at_a_settled_saga_skips_before_any_plan(): void
    {
        $plan = $this->policy->plan(Signal::familyPoke(), $this->settled('await'), $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::AlreadySettled, $plan->reason);
    }

    #[Test]
    public function a_family_poke_at_a_correlation_with_no_instance_skips(): void
    {
        $plan = $this->policy->plan(Signal::familyPoke(), null, $this->def(), $this->now());

        $this->assertInstanceOf(Skip::class, $plan);
        $this->assertSame(SkipReason::NotFound, $plan->reason);
    }

    private function def(?int $globalTimeout = 60): WorkflowDefinition
    {
        $charge = new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await'), new Transition(OnTrigger::Timeout, 'done')]);
        $await = new WaitState('await', transitions: [new Transition(OnTrigger::Event, 'done', onEvent: SampleEvent::class)]);
        $lobby = new WaitState('lobby', transitions: [new Transition(OnTrigger::Event, 'done', onEvent: SampleEvent::class), new Transition(OnTrigger::Timeout, 'done')]);
        $recharge = new ActivityState('recharge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'retry_await')]);
        $retryAwait = new WaitState('retry_await', retriable: true, transitions: [new Transition(OnTrigger::Event, 'done', onEvent: SampleEvent::class)]);

        return new WorkflowDefinition(
            'payment',
            ['charge' => $charge, 'await' => $await, 'lobby' => $lobby, 'recharge' => $recharge, 'retry_await' => $retryAwait, 'done' => new FinalState('done')],
            'charge',
            $globalTimeout,
        );
    }

    // -------------------------------------------------- user signals

    #[Test]
    public function a_user_signal_with_a_declared_handler_applies(): void
    {
        $def = $this->defWithSignalHandler();

        $plan = $this->policy->plan(Signal::user(new SampleEvent), $this->running('await'), $def, $this->now());

        $this->assertInstanceOf(ApplyUserSignal::class, $plan);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_user_signal_without_a_handler_is_dropped_with_a_reason(): void
    {
        // no buffering; the drop is observable via a SkipReason, never a thrown DLQ
        $plan = $this->policy->plan(Signal::user(new SampleEvent), $this->running('await'), $this->def(), $this->now());

        $this->assertSkip(SkipReason::NoSignalHandler, $plan);
    }

    private function defWithSignalHandler(): WorkflowDefinition
    {
        $base = $this->def();

        return new WorkflowDefinition(
            'payment',
            ['charge' => $base->state('charge'), 'await' => $base->state('await'), 'done' => $base->state('done')],
            'charge',
            signalHandlers: [SampleEvent::class => static fn (object $signal, array $vars): SignalResult => SignalResult::stay($vars)],
        );
    }

    /**
     * The same graph as `def()`, born with a 30 second birth delay; `now()` sits inside it.
     */
    private function defDeferred(): WorkflowDefinition
    {
        $base = $this->def();

        return new WorkflowDefinition('payment', $base->states(), 'charge', $base->globalTimeout, startAfterSeconds: 30);
    }

    /**
     * @param  array{state: string, event: class-string, cause: string|null}|null  $parked
     */
    private function parkedAt(string $stateKey, ?array $parked): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'c1', $stateKey, WorkflowStatus::Running, startedAt: $this->startedAt, parked: $parked);
    }

    private function running(string $stateKey): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'c1', $stateKey, WorkflowStatus::Running, startedAt: $this->startedAt);
    }

    private function settled(string $stateKey): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'c1', $stateKey, WorkflowStatus::Completed, startedAt: $this->startedAt);
    }

    private function now(): PointInTime
    {
        return $this->startedAt->addSeconds(5); // comfortably inside the 60s deadline
    }

    private function wayPastDeadline(): PointInTime
    {
        return $this->startedAt->addSeconds(3600); // far beyond startedAt + 60s
    }
}
