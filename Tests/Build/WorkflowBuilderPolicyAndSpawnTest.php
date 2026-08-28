<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\CircuitBreaker;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\CorrelationReuse;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\WaitState;

final class WorkflowBuilderPolicyAndSpawnTest extends TestCase
{
    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_bare_retry_that_never_retries(): void
    {
        // #[Retry] with the default maxAttempts of 0 retries nothing; inert protection reads as
        // protection that is not there
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxAttempts must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_on_a_non_wait_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retimable(state: 'work', maxRetimes: 3)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Retimable.*not a wait state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_without_any_cap(): void
    {
        // an uncapped retime lets chatter push a business deadline forever, the starvation the
        // engine refuses by default; the grant must be bounded to exist
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 3600, onDeadline: 'expired')]
        #[Retimable(state: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/declares no cap/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_on_an_effect_gating_wait(): void
    {
        // the wait gates an issued effect: its deadline belongs to the engine, which re-arms and
        // escalates; a signal moving it could silence the liveness of an effect in flight
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'issue', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[Retimable(state: 'await', maxRetimes: 3)]
        #[On(from: 'issue', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/effect-gating wait/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_on_a_wait_without_a_deadline(): void
    {
        // an untimed wait has no timer to move; granting a retime there promises a lever that
        // does not exist
        $wf = new #[Workflow(name: 'bad', globalTimeout: 3600)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[Retimable(state: 'await', maxRetimes: 3)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/no finalizing deadline/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function a_retimable_deadline_wait_builds_and_carries_its_policy(): void
    {
        $wf = new #[Workflow(name: 'kyc')]
        #[State(key: 'await_docs', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[WaitFor(state: 'await_docs', events: SampleEvent::class, deadlineSeconds: 3600, onDeadline: 'expired')]
        #[Retimable(state: 'await_docs', maxRetimes: 2, maxExtensionSeconds: 172_800)]
        #[On(from: 'await_docs', trigger: 'event', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $state = $def->state('await_docs');

        $this->assertInstanceOf(WaitState::class, $state);
        $this->assertNotNull($state->retime);
        $this->assertSame(2, $state->retime->maxRetimes);
        $this->assertSame(172_800, $state->retime->maxExtensionSeconds);
    }

    #[Test]
    public function accepts_a_retry_elapsed_budget_that_sits_under_the_global_timeout(): void
    {
        // The guard fires on `!== null AND >= globalTimeout`, and only the conjunction makes it a
        // guard: read as a disjunction, every declared budget would be refused, however small. The
        // rejection twin lives beside it; this is the case that must BUILD.
        $wf = new #[Workflow(name: 'under', globalTimeout: 60)]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 3, maxElapsedSeconds: 30)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $state = $this->builder()->build($wf)->state('work');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertSame(30, $state->retry?->maxElapsedSeconds);
    }

    #[Test]
    public function accepts_retry_budgets_sitting_exactly_on_their_floor(): void
    {
        // The floor twin of the three rejections around it. One attempt, one second of budget and one
        // second of requested delay is the smallest policy that IS a policy; without this the guards
        // read `<= 1` and would refuse it, silently.
        $wf = new #[Workflow(name: 'floor')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 1, maxElapsedSeconds: 1, maxRequestedDelaySeconds: 1)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $state = $this->builder()->build($wf)->state('work');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertNotNull($state->retry);
        $this->assertSame(1, $state->retry->maxAttempts);
        $this->assertSame(1, $state->retry->maxElapsedSeconds);
        $this->assertSame(1, $state->retry->maxRequestedDelaySeconds);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_non_positive_retry_elapsed_budget(): void
    {
        // a zero time budget refuses every retry the attempt cap would grant; inert protection
        // reads as protection that is not there, the maxAttempts < 1 sibling
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 3, maxElapsedSeconds: 0)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxElapsedSeconds.*must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_non_positive_requested_delay_grant(): void
    {
        // a zero cap grants the right to honor a requested delay of nothing: the declaration
        // pretends a capacity the runtime clamp immediately voids
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 3, maxRequestedDelaySeconds: 0)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxRequestedDelaySeconds.*must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retry_elapsed_budget_at_or_above_the_global(): void
    {
        // the retry-budget twin of the per-state-timeout guard: a visit window the global cap
        // always beats is dead config that reads as a bound it never is
        $wf = new #[Workflow(name: 'bad', globalTimeout: 30, onGlobalTimeout: 'failed')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 10)]
        #[State(key: 'failed', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 3, maxElapsedSeconds: 30)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxElapsedSeconds: 30.*globalTimeout=30/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_cooldown_circuit_breaker(): void
    {
        // cooldownSeconds < 1 admits every call while "open"; the fast-fail is silently OFF
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[CircuitBreaker(state: 'work', resource: 'gw', cooldownSeconds: 0)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cooldownSeconds must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_threshold_circuit_breaker(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[CircuitBreaker(state: 'work', resource: 'gw', failureThreshold: 0)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/failureThreshold must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_single_attempt_retry_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 1)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    // duplicate decorators: last-wins is a silent merge hazard

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_wait_for_on_one_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[WaitFor(state: 'await', events: SettlementSettled::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[WaitFor\] attributes target state "await"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_retries_on_one_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Retry(state: 'work', maxAttempts: 3)]
        #[Retry(state: 'work', maxAttempts: 5)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[Retry\] attributes target state "work"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_compensates_on_one_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Compensate(state: 'work', activity: RecordingActivity::class)]
        #[Compensate(state: 'work', activity: RecordingActivity::class)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[Compensate\] attributes target state "work"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_circuit_breakers_on_one_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[CircuitBreaker(state: 'work', resource: 'gw-a')]
        #[CircuitBreaker(state: 'work', resource: 'gw-b')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[CircuitBreaker\] attributes target state "work"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_schedules_on_one_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60)]
        #[Schedule(state: 'cadence', intervalSeconds: 90)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[Schedule\] attributes target state "cadence"/');
        $this->builder()->build($wf);
    }
    // mutation killers: sequencing, boundaries, and orthogonal business fields

    #[Test]
    public function a_durable_sleep_may_use_a_business_deadline(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cooldown', type: 'wait')]
        #[State(key: 'resume', type: 'final')]
        #[WaitFor(state: 'cooldown', events: [], onDeadline: 'resume', deadlineBusinessDays: 1)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function a_durable_sleep_coexists_with_an_event_wait(): void
    {
        // the edge scan is scoped PER WAIT: the event wait's edges must never be attributed to the sleep
        $wf = new #[Workflow(name: 'ok', globalTimeout: 7200)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'cooldown', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'cooldown')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[WaitFor(state: 'cooldown', events: [], deadlineSeconds: 3600, onDeadline: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_eventless_sleep_does_not_shadow_a_later_waits_checks(): void
    {
        // the sleep is fine and SKIPPED; the scan must continue to the faulty wait declared after it
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'cooldown', type: 'wait')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'cooldown', events: [], deadlineSeconds: 60, onDeadline: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/nowhere to route/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_alias_wait_does_not_shadow_a_later_waits_checks(): void
    {
        // the alias wait stands down from COVERAGE only; the scan must continue to the wait declared after it
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'first', type: 'wait')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'first', trigger: 'event', to: 'await')]
        #[WaitFor(state: 'first', events: ['settlement.settled'])]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/nowhere to route/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_activity_timeout(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 0)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/timeoutSeconds: 0 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_one_second_activity_timeout_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 1)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        #[On(from: 'work', trigger: 'timeout', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function accepts_a_one_second_heartbeat_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 1)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function accepts_a_business_hours_only_deadline(): void
    {
        // hours without days: the two business fields are orthogonal, either alone is a full deadline
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessHours: 4)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_business_hours_deadline(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessHours: 0)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/adds up to zero/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_zero_day_business_deadline_carried_by_hours(): void
    {
        // days: 0 is legal when the hours carry the deadline: "within 4 business hours, today"
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessDays: 0, deadlineBusinessHours: 4)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function accepts_a_business_deadline_with_explicit_zero_hours(): void
    {
        // hours: 0 is legal when the days carry the deadline: T+1 business day, time-of-day kept
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessDays: 1, deadlineBusinessHours: 0)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_catch_up_limit(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60, catchUp: 'bounded', catchUpLimit: 0, idempotentTick: true)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/catchUpLimit: 0 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_catch_up_limit_of_one_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60, catchUp: 'bounded', catchUpLimit: 1, idempotentTick: true)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_catch_up_window(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60, catchUp: 'all', catchUpWindowSeconds: 0, idempotentTick: true)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/catchUpWindowSeconds: 0 .* must be >= 1/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_one_second_catch_up_window_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', intervalSeconds: 60, catchUp: 'all', catchUpWindowSeconds: 1, idempotentTick: true)]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $cadence = $this->builder()->build($wf)->state('cadence');

        // the boundary accepted AND the pass-through pinned: a window the assembler drops turns a
        // declared bound into a replay of every missed slot since the saga's birth, with nothing red
        self::assertInstanceOf(ScheduleState::class, $cadence);
        self::assertSame(1, $cadence->catchUp->windowSeconds);
    }

    #[Test]
    public function accepts_a_circuit_breaker_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[CircuitBreaker(state: 'work', resource: 'gw', failureThreshold: 1, cooldownSeconds: 1)]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function a_cron_method_may_return_a_stringable(): void
    {
        // the contract is `fn(array $vars): string`; a Stringable return is tolerated, cast at the seam
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'cadence', type: 'schedule')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'cadence', cronMethod: 'billingCron')]
        #[On(from: 'cadence', trigger: 'schedule', to: 'work')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function billingCron(array $vars): object
            {
                return new class()
                {
                    public function __toString(): string
                    {
                        return '0 0 1 * *';
                    }
                };
            }
        };

        $cadence = $this->builder()->build($wf)->state('cadence');

        self::assertInstanceOf(ScheduleState::class, $cadence);
        self::assertInstanceOf(CronCadence::class, $cadence->cadenceFor([]));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_negative_business_days_field_even_when_hours_compensate(): void
    {
        // -1 day + 4 hours "adds up" to 3, but a negative field is nonsense the calendar would silently
        // treat as zero; reject the field, not the sum
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, onDeadline: 'expired', deadlineBusinessDays: -1, deadlineBusinessHours: 4)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/negative or adds up to zero/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_one_second_global_timeout_at_the_boundary(): void
    {
        $wf = new #[Workflow(name: 'ok', globalTimeout: 1)]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function a_spawns_declaration_lands_on_the_definition_keyed_by_slot(): void
    {
        // the compile-time consent: the definition carries what the adoption proof reads at birth
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'kyc', workflow: 'kyc_review', awaitedBy: 'await_child')]
        class {};

        $def = $this->builder()->build($wf);

        // a plain slot is NOT a family: the flag defaults to false, so a bare declaration consents to
        // exactly one child rather than to a range nobody asked for
        $this->assertFalse($def->spawns['kyc']->indexed);

        $declared = $def->spawnFor('kyc');
        self::assertNotNull($declared);
        self::assertSame('kyc_review', $declared->workflow);
        self::assertSame('await_child', $declared->awaitedBy);
        self::assertNull($def->spawnFor('ghost'));
    }

    #[Test]
    public function an_indexed_family_consents_to_its_members_by_prefix(): void
    {
        // ONE declaration, runtime arity: any `leg-<i>` resolves to the family's consent, while a
        // non-indexed slot keeps consenting to exactly itself
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'leg', workflow: 'settlement_leg', awaitedBy: 'await_child', indexed: true)]
        #[Spawns(slot: 'solo', workflow: 'kyc_review', awaitedBy: 'await_child')]
        class {};

        $def = $this->builder()->build($wf);

        $member = $def->spawnFor('leg-3');
        self::assertNotNull($member);
        self::assertSame('settlement_leg', $member->workflow);
        self::assertTrue($member->indexed);
        self::assertNull($def->spawnFor('leg-x')); // not a member shape: no index, no consent
        self::assertNull($def->spawnFor('solo-1')); // a single-child slot consents to exactly itself
        // the member shape is the WHOLE slot, not a prefix of it: `leg-1-secondary` is its own name,
        // and reading the family out of its head would hand it consent nobody declared for it
        self::assertNull($def->spawnFor('leg-1-secondary'));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_spawns_on_a_reusable_workflow(): void
    {
        // a child correlation is minted from the parent correlation and the slot alone, with no
        // generation: a second run of a reusable parent would remint the first run's children and
        // count their claims as its own
        $wf = new #[Workflow(name: 'bad', reuse: CorrelationReuse::Allow)]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'kyc', workflow: 'kyc_review', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/reuse: Allow/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_reusable_workflow_that_spawns_nothing(): void
    {
        // the refusal is the combination's, not the policy's: a childless workflow reuses freely
        $wf = new #[Workflow(name: 'ok', reuse: CorrelationReuse::Allow)]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_static_slot_that_ends_in_a_member_index(): void
    {
        // The suffix is RESERVED on every slot, family or not, and no neighbouring declaration is
        // consulted to decide it. One grammar reads a slot back wherever a slot is met, including at
        // a settling child that holds nothing but its own slot, so a static `leg-2` would answer
        // "member of the family leg" to a question no declaration in this workflow can contradict.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'leg-2', workflow: 'settlement_leg', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/without a trailing index/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_indexed_family_whose_name_ends_in_an_index(): void
    {
        // members mint as `slot-<i>`: a family name already carrying a trailing index would make
        // one family's member read as another family's name
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'leg-2', workflow: 'settlement_leg', awaitedBy: 'await_child', indexed: true)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/without a trailing index/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function accepts_a_family_whose_name_carries_an_index_that_is_not_trailing(): void
    {
        // The guard reads `/-\d+$/`, and the anchor is the whole point: only a name ENDING in an
        // index collides with the `slot-<i>` minting. Unanchored, this perfectly legal name would be
        // refused, since `leg-1-secondary` contains `-1`.
        $wf = new #[Workflow(name: 'indexed')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'leg-1-secondary', workflow: 'settlement_leg', awaitedBy: 'await_child', indexed: true)]
        class {};

        $this->assertSame(['leg-1-secondary'], array_keys($this->builder()->build($wf)->spawns));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_slot_that_opens_like_a_family_member_without_closing_like_one(): void
    {
        // The reserved suffix above catches the EXACT member form; this catches what opens like one
        // and does not end like one, which the suffix rule cannot see. `leg-1x` sorts inside the
        // family's byte range, where only the digits-only suffix test keeps it out of the count, so
        // the declaration is refused rather than left leaning on that one filter.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'leg', workflow: 'settlement_leg', awaitedBy: 'await_child', indexed: true)]
        #[Spawns(slot: 'leg-1x', workflow: 'kyc_review', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/two declarations/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_plain_slot_declared_first_does_not_hide_a_shadowed_family_behind_it(): void
    {
        // the member scan walks the spawns and SKIPS the ones that are not indexed families; ending
        // on the first of them would let a shadowing slot through whenever a plain spawn happens to
        // be declared before the family, which is nothing but declaration order
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'kyc', workflow: 'kyc_review', awaitedBy: 'await_child')]
        #[Spawns(slot: 'leg', workflow: 'settlement_leg', awaitedBy: 'await_child', indexed: true)]
        #[Spawns(slot: 'leg-1x', workflow: 'screening', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/two declarations/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_spawn_slot_that_breaks_the_correlation_grammar(): void
    {
        // the slot rides the child correlation: runtime data or spaces would poison every scan on it
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'has space', workflow: 'kyc_review', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a valid static identifier/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_spawns_declarations_for_the_same_slot(): void
    {
        // one slot, one declared child type; two declarations would make the adoption proof ambiguous
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'kyc', workflow: 'kyc_review', awaitedBy: 'await_child')]
        #[Spawns(slot: 'kyc', workflow: 'screening', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/twice for slot "kyc"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_spawns_declaration_with_an_empty_child_type(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[Spawns(slot: 'kyc', workflow: '', awaitedBy: 'await_child')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/empty child workflow name/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_spawns_declaration_awaited_by_an_activity(): void
    {
        // awaitedBy names the wait that consumes the child's conclusion; an activity cannot consume
        // anything; a detached process belongs to a reaction, not to a child slot
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        #[Spawns(slot: 'kyc', workflow: 'kyc_review', awaitedBy: 'work')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/awaited by "work", which is not a declared wait state/');
        $this->builder()->build($wf);
    }
}
