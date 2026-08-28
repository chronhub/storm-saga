<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\ExposesState;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidResolvedInterval;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\PaymentWorkflow;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\ScheduleWorkflow;
use Storm\Saga\Tests\Fixture\SettlementConfirmed;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CompensationMode;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\WaitState;

final class WorkflowBuilderConstructionTest extends TestCase
{
    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }

    // construction: what a well-formed declaration builds

    #[Test]
    public function builds_the_definition_shape_from_the_attributes(): void
    {
        $definition = $this->builder()->build(new PaymentWorkflow);

        $this->assertSame('payment', $definition->name);
        $this->assertSame('charge', $definition->start);
        $this->assertSame(3600, $definition->globalTimeout);
        $this->assertSame('failed', $definition->onGlobalTimeout);
        $this->assertSame(1, $definition->stateVersion); // the undeclared default
        $definition->states()
            |> array_keys(...)
            |> (fn ($x) => $this->assertSame(['charge', 'await_capture', 'done', 'failed'], $x));
    }

    #[Test]
    public function builds_the_declared_state_version_onto_the_definition(): void
    {
        $wf = new #[Workflow(name: 'versioned_state', stateVersion: 3)]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->assertSame(3, $this->builder()->build($wf)->stateVersion);
    }

    #[Test]
    public function binds_a_declared_validate_state_method_and_leaves_it_null_when_absent(): void
    {
        $wf = new #[Workflow(name: 'guarded_state')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function validateState(array $vars): void
            {
                if (! array_key_exists('amount', $vars)) {
                    throw new InvalidArgumentException('amount is required');
                }
            }
        };

        $definition = $this->builder()->build($wf);
        $this->assertNotNull($definition->stateValidator);

        $this->expectException(InvalidArgumentException::class);
        ($definition->stateValidator)([]);
    }

    #[Test]
    public function a_workflow_without_validate_state_builds_a_null_validator(): void
    {
        $wf = new #[Workflow(name: 'bare_state')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->assertNull($this->builder()->build($wf)->stateValidator);
    }

    #[Test]
    public function binds_a_declared_migrate_state_method(): void
    {
        $wf = new #[Workflow(name: 'migrating', stateVersion: 2)]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             * @return array<string, mixed>
             */
            public function migrateState(int $from, array $vars): array
            {
                return [...$vars, 'hop' => $from];
            }
        };

        $definition = $this->builder()->build($wf);
        $this->assertNotNull($definition->stateMigrator);
        $this->assertSame(['hop' => 1], ($definition->stateMigrator)(1, []));
    }

    #[Test]
    public function a_workflow_without_migrate_state_builds_a_null_migrator(): void
    {
        $wf = new #[Workflow(name: 'unmigrating')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->assertNull($this->builder()->build($wf)->stateMigrator);
    }

    #[Test]
    public function builds_the_exposes_state_allowlist_in_declaration_order(): void
    {
        $wf = new #[Workflow(name: 'exposing')]
        #[ExposesState('status', 'amount_cents')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->assertSame(['status', 'amount_cents'], $this->builder()->build($wf)->exposedStateKeys);
    }

    #[Test]
    public function an_undeclared_exposure_builds_closed(): void
    {
        $wf = new #[Workflow(name: 'closed')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->assertSame([], $this->builder()->build($wf)->exposedStateKeys);
    }

    #[Test]
    public function rejects_a_blank_exposed_state_key(): void
    {
        $wf = new #[Workflow(name: 'sloppy')]
        #[ExposesState('status', '  ')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank key');

        $this->builder()->build($wf);
    }

    #[Test]
    public function rejects_a_duplicated_exposed_state_key(): void
    {
        $wf = new #[Workflow(name: 'twice')]
        #[ExposesState('status', 'status')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('declares key "status" twice');

        $this->builder()->build($wf);
    }

    #[Test]
    public function builds_the_activity_state_with_its_activity_retry_and_timeout(): void
    {
        $charge = $this->builder()->build(new PaymentWorkflow)->state('charge');

        $this->assertInstanceOf(ActivityState::class, $charge);
        $this->assertInstanceOf(RecordingActivity::class, $charge->activity); // resolved from the container
        $this->assertSame(3, $charge->retry?->maxAttempts);
        $this->assertSame(30, $charge->timeout?->seconds);

        // transitions: success to await_capture, failure to failed
        $this->assertSame(OnTrigger::Success, $charge->transitions[0]->trigger);
        $this->assertSame('await_capture', $charge->transitions[0]->to);
        $this->assertSame('failed', $charge->transitions[1]->to);
    }

    #[Test]
    public function builds_the_wait_state_splitting_classes_from_aliases_and_binding_the_matcher(): void
    {
        $await = $this->builder()->build(new PaymentWorkflow)->state('await_capture');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertSame([SampleEvent::class], $await->eventClasses); // a real class maps to the class list
        $this->assertSame(['capture.done'], $await->eventTypes);       // not a class maps to the alias list
        $this->assertSame(600, $await->timeout?->seconds);

        $this->assertNotNull($await->matcher);
        $this->assertTrue(($await->matcher)(new SampleEvent, []));     // bound to the instance, returns true
    }

    #[Test]
    public function binds_a_transition_guard_to_the_instance(): void
    {
        $await = $this->builder()->build(new PaymentWorkflow)->state('await_capture');

        $event = $await->transitions[0]; // event to done, guarded by isLargeEnough
        $this->assertSame('done', $event->to);
        $this->assertNotNull($event->guard);
        $this->assertTrue(($event->guard)(['amount' => 200]));
        $this->assertFalse(($event->guard)(['amount' => 50]));
    }

    #[Test]
    public function final_states_are_built(): void
    {
        $definition = $this->builder()->build(new PaymentWorkflow);

        $this->assertInstanceOf(FinalState::class, $definition->state('done'));
        $this->assertInstanceOf(FinalState::class, $definition->state('failed'));
    }

    #[Test]
    public function builds_the_schedule_state_with_its_cadence_catch_up_and_edge(): void
    {
        $tick = $this->builder()->build(new ScheduleWorkflow)->state('await_installment');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertInstanceOf(CronCadence::class, $tick->cadence);
        // the cron string was wired through: the slot after mid-June is the 1st of July
        $this->assertSame(
            '2026-07-01T00:00:00.000000+00:00',
            // a cron grid is absolute; the anchor argument is inert
            $tick->cadence->nextFireAfter(PointInTime::from('2026-06-15T00:00:00.000000Z'), PointInTime::from('2026-06-15T00:00:00.000000Z'))->toString(),
        );
        $this->assertSame(CatchUp::ReplayBounded, $tick->catchUp->mode);
        $this->assertSame(3, $tick->catchUp->limit);

        // the schedule edge fires the tick toward the charge activity
        $this->assertSame(OnTrigger::Schedule, $tick->transitions[0]->trigger);
        $this->assertSame('charge', $tick->transitions[0]->to);
    }

    #[Test]
    public function builds_the_schedule_state_with_its_declared_spread(): void
    {
        // pins the wiring: a dropped pass-through would not fail anything; spread would just
        // silently never apply, the runner sees null and skips the decoration
        $wf = new #[Workflow(name: 'spread_fees')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', intervalSeconds: 1800, spreadSeconds: 1800)]
        #[On(from: 'tick', trigger: 'schedule', to: 'charge')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $tick = $this->builder()->build($wf)->state('tick');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertSame(1800, $tick->spreadSeconds);
    }

    #[Test]
    public function builds_the_definition_with_its_declared_compensation_mode_and_label(): void
    {
        // the same pin as the spread's, on the two Workflow arguments nothing else reads: a
        // compensation dropped to its default turns a declared Strict rollback into BestEffort,
        // a swallowed undo failure where the author asked for a halt, with nothing red
        $wf = new #[Workflow(name: 'strict_rollback', compensation: CompensationMode::Strict, label: 'Strict rollback probe')]
        #[Start(state: 'work')]
        #[State(key: 'work', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'work', trigger: 'success', to: 'done')]
        class {};

        $definition = $this->builder()->build($wf);

        $this->assertSame(CompensationMode::Strict, $definition->compensation);
        $this->assertSame('Strict rollback probe', $definition->label);
    }

    #[Test]
    public function builds_a_per_instance_cron_from_a_vars_key(): void
    {
        $wf = new #[Workflow(name: 'sub_var')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', cronVar: 'billing_cron')]
        #[On(from: 'tick', trigger: 'schedule', to: 'charge')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $tick = $this->builder()->build($wf)->state('tick');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertNull($tick->cadence); // no fixed cadence; resolved per-instance from vars
        $cadence = $tick->cadenceFor(['billing_cron' => '0 0 15 * *']);
        $this->assertInstanceOf(CronCadence::class, $cadence);
        $this->assertStringContainsString('2026-06-15', $cadence->nextFireAfter(PointInTime::from('2026-06-01T00:00:00.000000Z'), PointInTime::from('2026-06-01T00:00:00.000000Z'))->toString());
    }

    #[Test]
    public function builds_a_per_instance_cron_from_a_workflow_method(): void
    {
        $wf = new #[Workflow(name: 'sub_method')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', cronMethod: 'billingCron')]
        #[On(from: 'tick', trigger: 'schedule', to: 'charge')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function billingCron(array $vars): string
            {
                return sprintf('0 0 %d * *', (int) $vars['day']);
            }
        };

        $tick = $this->builder()->build($wf)->state('tick');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertNull($tick->cadence);
        $cadence = $tick->cadenceFor(['day' => 20]);
        $this->assertInstanceOf(CronCadence::class, $cadence);
        $this->assertStringContainsString('2026-06-20', $cadence->nextFireAfter(PointInTime::from('2026-06-01T00:00:00.000000Z'), PointInTime::from('2026-06-01T00:00:00.000000Z'))->toString());
    }

    #[Test]
    public function builds_a_per_instance_interval_from_a_vars_key(): void
    {
        $wf = new #[Workflow(name: 'refresh_var')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'review', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', intervalVar: 'review_interval')]
        #[On(from: 'tick', trigger: 'schedule', to: 'review')]
        #[On(from: 'review', trigger: 'success', to: 'done')]
        class {};

        $tick = $this->builder()->build($wf)->state('tick');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertNull($tick->cadence); // no fixed cadence; resolved per-instance from vars
        $cadence = $tick->cadenceFor(['review_interval' => 900]);
        $this->assertInstanceOf(IntervalCadence::class, $cadence);
        // anniversary semantics preserved: the grid is phased on the anchor, not the calendar
        $this->assertSame(900, $cadence->seconds);
        $this->assertSame(
            '2026-06-01T00:15:00.000000+00:00',
            $cadence->nextFireAfter(PointInTime::from('2026-06-01T00:00:00.000000Z'), PointInTime::from('2026-06-01T00:00:00.000000Z'))->toString(),
        );
        // the resolver is opaque, so the declared SOURCE is kept beside it; an interval source falls
        // back from the cron one, and nothing else records where this cadence came from
        $this->assertSame('review_interval', $tick->cadenceVar);
        $this->assertNull($tick->cadenceMethod);
    }

    #[Test]
    public function builds_a_per_instance_interval_from_a_workflow_method(): void
    {
        $wf = new #[Workflow(name: 'refresh_method')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'review', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', intervalMethod: 'reviewInterval')]
        #[On(from: 'tick', trigger: 'schedule', to: 'review')]
        #[On(from: 'review', trigger: 'success', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function reviewInterval(array $vars): int
            {
                return $vars['tier'] === 'enhanced' ? 900 : 3600;
            }
        };

        $tick = $this->builder()->build($wf)->state('tick');

        $this->assertInstanceOf(ScheduleState::class, $tick);
        $this->assertNull($tick->cadence);
        $enhanced = $tick->cadenceFor(['tier' => 'enhanced']);
        $this->assertInstanceOf(IntervalCadence::class, $enhanced);
        $this->assertSame(900, $enhanced->seconds);
        $standard = $tick->cadenceFor(['tier' => 'standard']);
        $this->assertInstanceOf(IntervalCadence::class, $standard);
        $this->assertSame(3600, $standard->seconds); // two instances, two periods, one declaration
        // the method source falls back from the cron one the same way the var source does
        $this->assertSame('reviewInterval', $tick->cadenceMethod);
        $this->assertNull($tick->cadenceVar);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_per_instance_interval_resolving_non_positive_throws_at_the_tick(): void
    {
        // the vars key is absent, the resolver collapses to the invalid sentinel 0, and the tick's
        // runtime guard names the state: the interval twin of InvalidResolvedCron, never a silent
        // grid that cannot advance
        $wf = new #[Workflow(name: 'refresh_bad')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'review', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', intervalVar: 'review_interval')]
        #[On(from: 'tick', trigger: 'schedule', to: 'review')]
        #[On(from: 'review', trigger: 'success', to: 'done')]
        class {};

        $tick = $this->builder()->build($wf)->state('tick');
        $this->assertInstanceOf(ScheduleState::class, $tick);

        $this->expectException(InvalidResolvedInterval::class);
        // the guard reports the sentinel itself: an absent var resolved to 0, not some invented number
        $this->expectExceptionMessageMatches('/"tick".+to 0 seconds.+must be positive/');

        $tick->cadenceFor([]);
    }

    #[Test]
    public function a_one_second_interval_is_the_floor_and_is_accepted(): void
    {
        // the boundary of the runtime guard: exactly one second is a valid grid, not a refusal;
        // the floor belongs to the accepted side, an off-by-one here would kill the tightest cadence
        $wf = new #[Workflow(name: 'refresh_floor')]
        #[Start(state: 'tick')]
        #[State(key: 'tick', type: 'schedule')]
        #[State(key: 'review', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[Schedule(state: 'tick', intervalVar: 'review_interval')]
        #[On(from: 'tick', trigger: 'schedule', to: 'review')]
        #[On(from: 'review', trigger: 'success', to: 'done')]
        class {};

        $tick = $this->builder()->build($wf)->state('tick');
        $this->assertInstanceOf(ScheduleState::class, $tick);

        $cadence = $tick->cadenceFor(['review_interval' => 1]);
        $this->assertInstanceOf(IntervalCadence::class, $cadence);
        $this->assertSame(1, $cadence->seconds);
    }

    #[Test]
    public function builds_a_wait_with_a_business_time_deadline(): void
    {
        $wf = new #[Workflow(name: 'sla')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[WaitFor(state: 'await', events: [stdClass::class], onDeadline: 'expired', deadlineBusinessDays: 2)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $await = $this->builder()->build($wf)->state('await');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertNotNull($await->timeout);
        $this->assertTrue($await->timeout->isBusiness());
        $this->assertSame(2, $await->timeout->businessDays);
        $this->assertSame(0, $await->timeout->seconds); // wall-clock seconds 0; the deadline is purely business-time
        // onDeadline desugared into a timeout edge, like a wall-clock deadline
        $this->assertTrue(array_any($await->transitions, static fn ($t): bool => $t->trigger === OnTrigger::Timeout && $t->to === 'expired'));
    }

    #[Test]
    public function carries_the_retry_budget_onto_the_definition(): void
    {
        $wf = new #[Workflow(name: 'rb', retryBudget: 5)]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $this->assertSame(5, $def->retryBudget);
        $this->assertSame(1, $def->version); // no version on the #[Workflow], so the attribute's default 1, carried through
    }

    #[Test]
    public function accepts_a_retry_budget_of_exactly_one(): void
    {
        // the boundary: 1 is the smallest VALID budget; a `<= 1` guard would wrongly reject it
        $wf = new #[Workflow(name: 'rb', retryBudget: 1)]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->assertSame(1, $this->builder()->build($wf)->retryBudget);
    }

    #[Test]
    public function rejects_a_retry_budget_below_one(): void
    {
        $wf = new #[Workflow(name: 'rb', retryBudget: 0)]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/retryBudget must be >= 1/');
        $this->builder()->build($wf);
    }

    // declared values that cannot act: a knob accepted here is a knob the author believes in and the
    // engine ignores, so the catalog refuses it rather than letting it read as configuration

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_workflow_name(): void
    {
        $wf = new #[Workflow(name: '  ')]
        #[Start(state: 'done')]
        #[State(key: 'done', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank name');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_state_key(): void
    {
        $wf = new #[Workflow(name: 'blank_key')]
        #[Start(state: '  ')]
        #[State(key: '  ', type: 'final')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank key');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retry_base_delay_below_one_millisecond(): void
    {
        // baseMs 0 clamps to 1ms per attempt: a declared backoff that does not back off, so the
        // attempts burn in a few milliseconds against a downstream that is still down
        $wf = new #[Workflow(name: 'hot_retry')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[Retry(state: 'charge', maxAttempts: 3, baseMs: 0)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('base delay must be >= 1 millisecond');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_do_not_retry_pattern(): void
    {
        // a blank pattern is a substring of every class and message, so it excludes EVERY error: the
        // state declares three attempts and never retries once
        $wf = new #[Workflow(name: 'never_retries')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[Retry(state: 'charge', maxAttempts: 3, doNotRetryOn: [''])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank pattern');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_retry_pattern(): void
    {
        $wf = new #[Workflow(name: 'retries_everything')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[Retry(state: 'charge', maxAttempts: 3, retryOn: ['  '])]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank pattern');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_transition_shadowed_by_an_unguarded_sibling(): void
    {
        // the selector takes the first match, so an unguarded edge answers for every later sibling on
        // the same trigger: the second edge is dead code a typo can produce in silence
        $wf = new #[Workflow(name: 'shadowed')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'elsewhere', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[On(from: 'charge', trigger: 'success', to: 'elsewhere')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('unreachable');

        $this->builder()->build($wf);
    }

    #[Test]
    public function a_guarded_transition_may_precede_a_sibling_on_the_same_trigger(): void
    {
        // the shape the shadowing rule must not break: the guard is what makes the fall-through real
        $wf = new #[Workflow(name: 'guarded_pair')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'elsewhere', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done', guard: 'isSettled')]
        #[On(from: 'charge', trigger: 'success', to: 'elsewhere')]
        class
        {
            /** @param  array<string, mixed>  $vars */
            public function isSettled(array $vars): bool
            {
                return (bool) ($vars['settled'] ?? false);
            }
        };

        $this->assertCount(2, $this->builder()->build($wf)->state('charge')->transitions);
    }

    #[Test]
    public function a_retry_base_delay_of_one_millisecond_builds(): void
    {
        // the boundary the refusal sits at: one millisecond is a backoff, zero is not
        $wf = new #[Workflow(name: 'minimal_backoff')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[Retry(state: 'charge', maxAttempts: 3, baseMs: 1)]
        class {};

        $charge = $this->builder()->build($wf)->state('charge');

        $this->assertInstanceOf(ActivityState::class, $charge);
        $this->assertSame(1, $charge->retry?->baseMs);
    }

    #[Test]
    public function an_unguarded_event_transition_may_precede_a_sibling_on_another_event(): void
    {
        // shadowing is scoped per event class too: the selector matches the arrived class exactly
        // before it ever considers a catch-all, so two named events never compete for one fire
        $wf = new #[Workflow(name: 'two_events', globalTimeout: 3600)]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'elsewhere', type: 'final')]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementConfirmed::class])]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'elsewhere', onEvent: SettlementConfirmed::class)]
        class {};

        $this->assertCount(2, $this->builder()->build($wf)->state('await')->transitions);
    }

    #[Test]
    public function an_unguarded_transition_may_precede_a_sibling_on_another_trigger(): void
    {
        // shadowing is per trigger: success and failure never compete for the same fire
        $wf = new #[Workflow(name: 'two_triggers')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'elsewhere', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        #[On(from: 'charge', trigger: 'failure', to: 'elsewhere')]
        class {};

        $this->assertCount(2, $this->builder()->build($wf)->state('charge')->transitions);
    }
}
