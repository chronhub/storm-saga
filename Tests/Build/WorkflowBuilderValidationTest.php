<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\CircuitBreaker;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\Fallback;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\AbstractSettlement;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\PaymentWorkflow;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementContract;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\ScheduleState;

final class WorkflowBuilderValidationTest extends TestCase
{
    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }

    // --- adversarial: malformed declarations rejected at build by the #[Workflow] API guards ---

    // declaration rules: the validator on the key set, in catalog order

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_workflow_with_no_states(): void
    {
        $wf = new #[Workflow(name: 'bad')] class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/declares no states/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_duplicate_state_keys(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'a', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/more than once/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retry_declared_on_a_wait_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'w', type: 'wait')]
        #[WaitFor(state: 'w', events: SampleEvent::class)]
        #[Retry(state: 'w', maxAttempts: 3)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[Retry\].*not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_compensate_declared_on_a_final_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Compensate(state: 'done', activity: RecordingActivity::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[Compensate\].*not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_circuit_breaker_declared_on_a_wait_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'w', type: 'wait')]
        #[WaitFor(state: 'w', events: SampleEvent::class)]
        #[CircuitBreaker(state: 'w', resource: 'gateway')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[CircuitBreaker\].*not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_fallback_declared_on_a_wait_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'w', type: 'wait')]
        #[WaitFor(state: 'w', events: SampleEvent::class)]
        #[Fallback(state: 'w', vars: ['x' => 1])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[Fallback\].*not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_for_on_a_non_wait_state(): void
    {
        // a #[WaitFor] on an activity/final state is meaningless and silently dropped, so the dev thinks the
        // state waits. Reject it at build, like the retry/compensation/breaker/fallback decorators.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[WaitFor(state: 'a', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[WaitFor\(state: "a"\)\].*not a wait state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_transition_from_an_unknown_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[On(from: 'ghost', trigger: 'success', to: 'a')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/references an undeclared state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_transition_to_an_unknown_state(): void
    {
        // the builder validates On.from; an undeclared On.to must fail fast at build too, for symmetry.
        // Otherwise a typo'd target only surfaces as a runtime UnknownState mid-saga, after side effects.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[On(from: 'a', trigger: 'success', to: 'ghost')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[On\(to: "ghost"\)\].*undeclared state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_on_event_on_a_non_event_trigger(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done', onEvent: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/onEvent.*non-event/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_on_event_naming_a_nonexistent_class(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'event', to: 'done', onEvent: 'App\\Nope\\Ghost')] // @phpstan-ignore argument.type (the point: a bad class-string)
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/names a class\/interface that does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_start_pointing_at_an_unknown_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Start(state: 'nope')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/#\[Start\].*unknown state "nope"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_global_timeout_pointing_at_an_unknown_state(): void
    {
        // like #[Start], onGlobalTimeout names a state to drive into; an undeclared one must fail fast at
        // build, not as a runtime UnknownState when the deadline eventually fires.
        $wf = new #[Workflow(name: 'bad', globalTimeout: 60, onGlobalTimeout: 'ghost')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/onGlobalTimeout.*unknown state "ghost"/');
        $this->builder()->build($wf);
    }

    // assembled rules: the validator on the finished map

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_timeout_transition_on_a_wait_that_gates_an_issued_effect(): void
    {
        // 'a' is an activity whose `success` transitions to the wait 'await', an issue-and-wait step, so
        // 'await' gates a's possibly-committed in-flight effect, even though 'a' has NO #[Compensate]:
        // detection is structural, not confirmedBy-based. A timeout there can't tell a committed-but-
        // unconfirmed effect from a failed one, so the engine owns that deadline; declaring it is rejected.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'failed')]
        #[On(from: 'await', trigger: 'timeout', to: 'failed')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/owned by the engine/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function a_non_gating_wait_may_declare_a_timeout_transition(): void
    {
        // 'w' does NOT gate an issued effect since the only activity's success goes elsewhere, so a timeout
        // transition on it is legitimate and must build; the engine-owned-timeout rejection is for
        // gating waits only. Pins EffectGating::gates() returning false here, its scan finding no
        // success edge landing on 'w'.
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'other', type: 'final')]
        #[State(key: 'w', type: 'wait')]
        #[WaitFor(state: 'w', events: SampleEvent::class)]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'other')] // success goes elsewhere, so 'w' is not gated
        #[On(from: 'w', trigger: 'event', to: 'other')]
        #[On(from: 'w', trigger: 'timeout', to: 'expired')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function gating_detection_does_not_stop_at_a_non_activity_state(): void
    {
        // a non-activity state 'failed' is declared between the start and the gating activity 'b'.
        // The scan must skip it and keep looking with continue, not break, or it never sees 'b' gate 'await'.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'failed', type: 'final')]
        #[State(key: 'b', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'a', trigger: 'success', to: 'b')]
        #[On(from: 'b', trigger: 'success', to: 'await')] // 'b' gates 'await'
        #[On(from: 'await', trigger: 'event', to: 'failed')]
        #[On(from: 'await', trigger: 'timeout', to: 'failed')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/owned by the engine/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_per_state_timeout_greater_than_the_global(): void
    {
        // Per-state timer can never fire; the global pre-empts it. Silent dead-coded transition.
        $wf = new #[Workflow(name: 'bad', globalTimeout: 30, onGlobalTimeout: 'failed')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'failed')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds=60.*greater than.*globalTimeout=30.*would never fire/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_per_state_timeout_equal_to_the_global(): void
    {
        // Timer ordering in the runner is non-deterministic at the tick; the per-state cannot achieve
        // its purpose of acting before the global, so reject the race at build.
        $wf = new #[Workflow(name: 'bad', globalTimeout: 30, onGlobalTimeout: 'failed')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'failed')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds=30.*equal to.*globalTimeout=30.*would race/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_activity_per_state_timeout_at_or_above_the_global(): void
    {
        // the at-or-above-global guard applies to ACTIVITY states too, not only waits
        $wf = new #[Workflow(name: 'bad', globalTimeout: 30, onGlobalTimeout: 'failed')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 60)]
        #[State(key: 'failed', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds=60.*greater than.*globalTimeout=30/');
        $this->builder()->build($wf);
    }

    // constructive failures: the builder's own steps

    #[Test]
    public function rejects_a_class_without_the_workflow_attribute(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('is not a workflow: missing #[Workflow]');
        $this->builder()->build(new stdClass);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_activity_state_with_no_activity(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity')] // no activity FQCN
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/declares no activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function rejects_an_activity_that_does_not_resolve_to_an_activity(): void
    {
        $builder = new WorkflowBuilder(new ArrayContainer([RecordingActivity::class => new stdClass]));

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a resolvable Activity/i');
        $builder->build(new PaymentWorkflow);
    }

    // --- adversarial: build-time coherence via silent-ignore guards ---

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_state_with_no_wait_for(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')] // no #[WaitFor]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/has no #\[WaitFor\]/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_schedule_state_with_no_schedule(): void
    {
        // the schedule-state twin of the wait guard above: a `type: 'schedule'` state with no matching
        // #[Schedule] has no cadence to build from; construction must fail, not silently skip the state
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 's', type: 'schedule')] // no #[Schedule]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/has no #\[Schedule\]/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_compensate_confirmed_by_a_nonexistent_class(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Compensate(state: 'a', activity: RecordingActivity::class, confirmedBy: 'App\\Nope\\DoesNotExist')] // @phpstan-ignore argument.type (the point of the test: a bad class-string)
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/confirmedBy.*does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_compensation_activity_that_does_not_resolve_to_an_activity(): void
    {
        // SampleEvent is mapped to a non-Activity in the container, so the compensation activity is unresolvable.
        $builder = new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
            SampleEvent::class => new stdClass,
        ]));
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Compensate(state: 'a', activity: SampleEvent::class)] // mapped to a non-Activity on purpose
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a resolvable Activity/i');
        $builder->build($wf);
    }

    // --- extract: #[WaitFor(extract:)] pulls event fields into named vars, the matcher's data-side twin ---

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_circuit_breaker_with_an_empty_key(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[CircuitBreaker(state: 'a', resource: '')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/needs a non-empty `resource`/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_circuit_breaker_with_a_whitespace_only_key(): void
    {
        // the key is rejected after trim; a blank-but-non-empty resource is still no resource
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[CircuitBreaker(state: 'a', resource: '   ')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/needs a non-empty `resource`/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_transition_guard_naming_an_unknown_method(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done', guard: 'ghostGuard')] // no such method on the class
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/method "ghostGuard".*does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_fallback_setting_both_activity_and_vars(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Fallback(state: 'a', activity: RecordingActivity::class, vars: ['x' => 1])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/sets both `activity` and `vars`/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_fallback_with_neither_activity_nor_vars(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Fallback(state: 'a')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/needs an `activity` or `vars`/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_fallback_activity_that_does_not_resolve_to_an_activity(): void
    {
        // SampleEvent is mapped to a non-Activity in the container, so the fallback activity is unresolvable.
        $builder = new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
            SampleEvent::class => new stdClass,
        ]));
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[Fallback(state: 'a', activity: SampleEvent::class)] // mapped to a non-Activity on purpose
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a resolvable Activity/i');
        $builder->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_extract_naming_an_unknown_method(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: 'ghostPull')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/method "ghostPull".*does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_empty_extract_map(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: [])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/empty `extract` map/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_malformed_extract_map(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: ['captured_cents' => 123])] // @phpstan-ignore argument.type (the point: a non-string map value)
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/malformed `extract` map/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_extract_map_with_an_empty_target_key(): void
    {
        // an empty target key is as malformed as a non-string field, the var name must be a real key
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: ['' => 'amount'])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/malformed `extract` map/');
        $this->builder()->build($wf);
    }
    // gating liveness: a lost outcome must have a signal

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_gating_wait_with_no_heartbeat_and_no_global_cap(): void
    {
        // gating + no heartbeat + no globalTimeout: a lost outcome event strands the saga SILENTLY:
        // running, zero timers, invisible to reconciliation, which derives from dead-letters, not silence
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        // the GATING refusal specifically: both liveness messages say "declares no liveness", and only
        // this one may offer a heartbeat, which the other explicitly rules out
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('gates an issued effect');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_gating_wait_whose_liveness_is_the_heartbeat(): void
    {
        // the twin accepted shape: same graph, the heartbeat IS the liveness, no global needed;
        // the global-cap variant is pinned by a separate test
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    // compensation at a gating wait: issuance is not proof

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_compensable_issue_and_wait_step_without_confirmed_by(): void
    {
        // at the gating wait, progression only proves the step RAN: a command was written, not that its
        // effect committed. A settle / forced cancel there runs the POSITIONAL rollback, where an
        // untracked entry is eligible, and would undo an effect that may never have happened
        $wf = new #[Workflow(name: 'bad')]
        #[Start(state: 'prep')]
        #[State(key: 'prep', type: 'activity', activity: RecordingActivity::class)] // non-compensable first; the scan must not stop at it
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[Compensate(state: 'charge', activity: RecordingActivity::class)]
        #[On(from: 'prep', trigger: 'success', to: 'charge')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/declares no confirmedBy/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_compensable_issue_and_wait_step_naming_its_confirming_event(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[Compensate(state: 'charge', activity: RecordingActivity::class, confirmedBy: SampleEvent::class)]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function accepts_a_compensable_step_whose_success_target_is_not_a_wait(): void
    {
        // a synchronous shape, success straight to a final: progression IS proof there, the positional
        // rollback is sound without confirmedBy; the rule only bites on issue-and-wait
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Compensate(state: 'charge', activity: RecordingActivity::class)]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    // schedule edges: the cadence must always land somewhere

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_schedule_state_whose_every_schedule_edge_is_guarded(): void
    {
        // a tick where all guards reject would HALT the recurring workflow permanently: the fired cadence
        // timer is consumed and nothing re-arms it; "skip this slot" belongs inside the tick
        $wf = new #[Workflow(name: 'bad')]
        #[Start(state: 'cadence')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)] // non-schedule first; the scan must not stop at it
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work', guard: 'shouldRun')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function shouldRun(array $vars): bool
            {
                return false;
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/every schedule edge is guarded/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_guarded_schedule_edges_over_an_unguarded_catch_all(): void
    {
        // conditional routing per tick is legitimate; the unguarded catch-all keeps the cadence alive
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'other', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'other', guard: 'isSpecial')]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        #[On(from: 'other', trigger: 'success', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function isSpecial(array $vars): bool
            {
                return false;
            }
        };

        $def = $this->builder()->build($wf);
        $cadence = $def->state('cadence');

        self::assertSame('ok', $def->name);
        self::assertInstanceOf(ScheduleState::class, $cadence);
        self::assertInstanceOf(IntervalCadence::class, $cadence->cadence); // the interval source really lands
    }

    // wait events: well-formed, routed, statically coherent

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_empty_event_entry(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: [''])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/empty event entry/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_mistyped_event_class_dressed_as_an_alias(): void
    {
        // the namespaced string resolves to nothing: without the guard it would silently become a type
        // alias that never matches, and the wait would never resolve
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: ['Storm\Saga\Tests\Fixture\SampleEventTypo'])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/silently become a type alias/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_eventless_wait_with_no_deadline(): void
    {
        // accepts nothing, expires never; the state can never be left
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: [])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/can never be left/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_an_eventless_wait_with_a_deadline_as_a_durable_sleep(): void
    {
        // a durable sleep: no events, the deadline is the only and sufficient way out
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cooldown', type: 'wait')]
        #[State(key: 'resume', type: 'final')]
        #[WaitFor(state: 'cooldown', events: [], deadlineSeconds: 3600, onDeadline: 'resume')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_whose_accepted_events_have_no_event_edge(): void
    {
        // a matched event with no edge halts the saga; the author declared the events but no route
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/nowhere to route/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_event_edge_on_an_eventless_wait(): void
    {
        // the edge can never fire; nothing ever matches
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'cooldown', type: 'wait')]
        #[State(key: 'resume', type: 'final')]
        #[On(from: 'cooldown', trigger: 'event', to: 'resume')]
        #[WaitFor(state: 'cooldown', events: [], deadlineSeconds: 3600, onDeadline: 'resume')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts no events/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_on_event_the_wait_never_accepts(): void
    {
        // the edge routes a class outside the accepted set, statically dead
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        #[WaitFor(state: 'await', events: SettlementSettled::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/the wait does not accept/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_an_on_event_covered_through_an_accepted_interface(): void
    {
        // the wait accepts a FAMILY, an interface matched by instanceof; a concrete member routes fine
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SettlementSettled::class)]
        #[WaitFor(state: 'await', events: SettlementContract::class)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function an_alias_accepting_wait_skips_the_on_event_coverage(): void
    {
        // a type alias resolves at runtime via EventResolver, so the accepted set is not statically known;
        // the coverage check stands down rather than guess
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        #[WaitFor(state: 'await', events: ['settlement.settled'])]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_interface_as_on_event(): void
    {
        // routing compares the DELIVERED event's concrete class; an interface edge is never selected:
        // the wait would match by instanceof, then halt with no route
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')] // a clean catch-all first; the scan must not stop at it
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SettlementContract::class)]
        #[WaitFor(state: 'await', events: SettlementContract::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/interface or abstract/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_abstract_class_as_on_event(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: AbstractSettlement::class)]
        #[WaitFor(state: 'await', events: AbstractSettlement::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/interface or abstract/');
        $this->builder()->build($wf);
    }

    // trigger × state-kind: a dead edge is a lie about a route

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_event_trigger_out_of_an_activity(): void
    {
        // an activity yields success/failure/timeout; the runner never selects an event edge there.
        // The wake contract re-EXECUTES a resting activity, it does not route by event.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'other', type: 'final')]
        #[On(from: 'done', trigger: 'success', to: 'other')] // an exempt final dead-edge first; the scan must not stop at it
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[On(from: 'charge', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/never yields the "event" trigger/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_success_trigger_out_of_a_wait(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[On(from: 'await', trigger: 'success', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/never yields the "success" trigger/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function a_final_state_keeps_its_dead_edges_constructible(): void
    {
        // the documented parity choice for FinalState: a transition out of a final is dead by construction
        // and deliberately NOT rejected; the trigger matrix exempts final states
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'other', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        #[On(from: 'done', trigger: 'success', to: 'other')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_timeout_seconds_on_a_wait_state(): void
    {
        // silently ignored: the author believes in a timer that is not there; a wait's deadline
        // lives on #[WaitFor]
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait', timeoutSeconds: 30)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds, which a wait state silently ignores/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_activity_field_on_a_final_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final', activity: RecordingActivity::class)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/activity, which a final state silently ignores/');
        $this->builder()->build($wf);
    }

    // durations: a zero timer is a config accident, not a choice

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_deadline(): void
    {
        // deadlineSeconds: 0, a config interpolation accident, would finalize a business decision one
        // second after the arm
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 0, onDeadline: 'expired')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/deadlineSeconds: 0 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_negative_heartbeat(): void
    {
        // a negative heartbeat would re-arm at the 1s floor: one escalation announcement per sweep, per saga
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: -5)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/heartbeatSeconds: -5 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_business_deadline_adding_up_to_zero(): void
    {
        // deadlineBusinessDays: 0 with no hours; due immediately, and the business path has no runtime floor
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessDays: 0)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/adding up to zero|adds up to zero/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_global_timeout(): void
    {
        $wf = new #[Workflow(name: 'bad', globalTimeout: 0)]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout: 0 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_one_second_deadline_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 1, onDeadline: 'expired')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }
}
