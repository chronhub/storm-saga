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
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\Spawns;
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
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\SignalResult;
use Storm\Saga\Workflow\WaitState;

final class WorkflowBuilderWaitAndTimeTest extends TestCase
{
    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_that_can_neither_ping_nor_expire(): void
    {
        // it gates no issued effect, so the gating rule never reaches it, and with no deadline and no cap
        // a lost message rests this saga forever: running, zero timers, nothing announced
        $wf = new #[Workflow(name: 'restless')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('declares no liveness');

        $this->builder()->build($wf);
    }

    #[Test]
    public function a_wait_awaiting_a_spawned_child_needs_no_clock_of_its_own(): void
    {
        // the one exemption, and it is DERIVED rather than declared: the child carries the horizon, so a
        // clock here would race it rather than protect it, and there is no opt-out to write
        $wf = new #[Workflow(name: 'parent')]
        #[Start(state: 'await_child')]
        #[Spawns(slot: 'leg', workflow: 'child', awaitedBy: 'await_child')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[On(from: 'await_child', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        class {};

        $await = $this->builder()->build($wf)->state('await_child');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertNull($await->timeout, 'the exemption must leave the wait genuinely clockless');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_gating_wait_awaiting_a_spawned_child_still_declares_its_liveness(): void
    {
        // the precedence the rule's docblock states: the child's clocks prove the child CONCLUDES,
        // never that its outcome ARRIVES, and an effect-gating wait watches exactly that delivery
        // seam, which only the parent can watch. The spawn exemption covers non-gating waits alone,
        // so this refusal must fire although the wait is spawn-awaited
        $wf = new #[Workflow(name: 'bad')]
        #[Start(state: 'fan')]
        #[State(key: 'fan', type: 'activity', activity: RecordingActivity::class)]
        #[Spawns(slot: 'leg', workflow: 'child', awaitedBy: 'await_child')]
        #[State(key: 'await_child', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await_child', events: SampleEvent::class)]
        #[On(from: 'fan', trigger: 'success', to: 'await_child')]
        #[On(from: 'await_child', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('gates an issued effect');
        $this->builder()->build($wf);
    }

    #[Test]
    public function a_wait_that_expires_on_its_own_deadline_needs_no_cap_above_it(): void
    {
        // the rail shape: a day-scale window finalizing itself, where a cap would only duplicate the
        // horizon and a heartbeat would escalate a step nobody is waiting on
        $wf = new #[Workflow(name: 'expiring')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 86_400, onDeadline: 'expired')]
        #[On(from: 'await', trigger: 'event', to: 'done', onEvent: SampleEvent::class)]
        class {};

        $await = $this->builder()->build($wf)->state('await');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertSame(86_400, $await->timeout?->seconds);
    }

    #[Test]
    public function a_birth_delay_under_the_global_cap_builds(): void
    {
        // a bare cap: the delay only has to leave the budget something to live on
        $wf = new #[Workflow(name: 'delayed', globalTimeout: 3600)]
        #[Start(state: 'charge', afterSeconds: 600)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->assertSame(600, $this->builder()->build($wf)->startAfterSeconds);
    }

    #[Test]
    public function a_birth_delay_with_no_global_cap_builds_and_defaults_stay_null(): void
    {
        $wf = new #[Workflow(name: 'delayed')]
        #[Start(state: 'charge', afterSeconds: 86400)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->assertSame(86400, $this->builder()->build($wf)->startAfterSeconds);

        $plain = new #[Workflow(name: 'plain')]
        #[Start(state: 'charge')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->assertNull($this->builder()->build($plain)->startAfterSeconds);
    }

    #[Test]
    public function accepts_a_birth_delay_of_exactly_one_second(): void
    {
        // The floor twin of the rejection below. A delay of one second is the smallest real delay;
        // without this the guard reads `<= 1` and would refuse it.
        $wf = new #[Workflow(name: 'delayed')]
        #[Start(state: 'charge', afterSeconds: 1)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->assertSame(1, $this->builder()->build($wf)->startAfterSeconds);
    }

    #[Test]
    public function rejects_a_non_positive_birth_delay(): void
    {
        $wf = new #[Workflow(name: 'delayed')]
        #[Start(state: 'charge', afterSeconds: 0)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/birth delay must be >= 1 second/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function rejects_a_birth_delay_that_reaches_the_global_timeout(): void
    {
        // the boundary is >=: a delay exactly AT the cap leaves the saga zero budget to live on
        $wf = new #[Workflow(name: 'delayed', globalTimeout: 600)]
        #[Start(state: 'charge', afterSeconds: 600)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/expire before it ever runs/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function resolves_a_compensation_activity_from_the_container(): void
    {
        // positive: a #[Compensate(activity:)] with no confirmedBy is resolved and attached to the activity state.
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Compensate(state: 'a', activity: RecordingActivity::class)]
        class {};

        $state = $this->builder()->build($wf)->state('a');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertNotNull($state->compensation); // the compensation activity was resolved + attached
    }

    #[Test]
    public function resolves_a_fallback_activity_from_the_container(): void
    {
        // positive: an #[Fallback(activity:)] is resolved to an ActivityFallback via the container.
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Fallback(state: 'a', activity: RecordingActivity::class)]
        class {};

        $state = $this->builder()->build($wf)->state('a');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertNotNull($state->fallback); // the fallback chain was built, an ActivityFallback resolved
    }

    #[Test]
    public function binds_a_method_form_extract_to_the_instance(): void
    {
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: 'pull')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             * @return array<string, mixed>
             */
            public function pull(object $event, array $vars): array
            {
                return ['captured_cents' => 19166];
            }
        };

        $w = $this->builder()->build($wf)->state('w');

        $this->assertInstanceOf(WaitState::class, $w);
        $this->assertNotNull($w->extract);
        $this->assertSame([], $w->extractMap);
        $this->assertSame(['captured_cents' => 19166], ($w->extract)(new SampleEvent, [])); // bound to the instance
    }

    #[Test]
    public function stores_a_map_form_extract_as_a_raw_map(): void
    {
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, extract: ['captured_cents' => 'amount'])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class {};

        $w = $this->builder()->build($wf)->state('w');

        $this->assertInstanceOf(WaitState::class, $w);
        $this->assertNull($w->extract);
        $this->assertSame(['captured_cents' => 'amount'], $w->extractMap); // kept raw for design-time inspection
    }

    #[Test]
    public function a_wait_without_an_extract_has_none(): void
    {
        // back-compat: a #[WaitFor] with no extract carries neither form, the existing fixture path.
        $await = $this->builder()->build(new PaymentWorkflow)->state('await_capture');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertNull($await->extract);
        $this->assertSame([], $await->extractMap);
    }

    #[Test]
    public function builds_the_signal_handler_map_bound_to_the_instance(): void
    {
        $wf = new #[Workflow(name: 'card', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: [stdClass::class])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            public int $bump = 500;

            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay([...$vars, 'held' => $vars['held'] + $this->bump]);
            }
        };

        $handler = $this->builder()->build($wf)->signalHandlerFor(new SampleEvent);

        $this->assertNotNull($handler);
        $result = $handler(new SampleEvent, ['held' => 1000]);
        $this->assertSame(['held' => 1500], $result->vars); // bound to the instance; $this->bump reached
        $this->assertSame([], $result->commands);
    }

    #[Test]
    public function a_signal_handler_may_type_its_first_parameter_with_the_signal_class(): void
    {
        // the lookup is exact-class: the engine never passes anything but the declared signal, so a
        // handler typed with it is always safe, and reads like a Messenger handler
        $wf = new #[Workflow(name: 'typed')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(SampleEvent $signal, array $vars): SignalResult
            {
                return SignalResult::stay([...$vars, 'seen' => $signal::class]);
            }
        };

        $handler = $this->builder()->build($wf)->signalHandlerFor(new SampleEvent);

        $this->assertNotNull($handler);
        $this->assertSame(['seen' => SampleEvent::class], $handler(new SampleEvent, [])->vars);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_whose_parameter_cannot_accept_the_signal(): void
    {
        // cross-wiring: the handler types a class UNRELATED to the declared signal; the engine
        // would TypeError mid-step on the first delivery, so the build refuses it
        $wf = new #[Workflow(name: 'crossed')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(SettlementSettled $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cannot accept the declared signal/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_with_a_scalar_first_parameter(): void
    {
        $wf = new #[Workflow(name: 'scalar')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(string $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cannot accept the declared signal/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function binds_one_handler_per_signal_class_side_by_side(): void
    {
        $wf = new #[Workflow(name: 'two')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        #[Signal(signal: SettlementSettled::class, handler: 'settle')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay([...$vars, 'raised' => true]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function settle(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay([...$vars, 'settled' => true]);
            }
        };

        $def = $this->builder()->build($wf);

        $this->assertNotNull($def->signalHandlerFor(new SampleEvent));
        $this->assertNotNull($def->signalHandlerFor(new SettlementSettled));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_that_cannot_see_the_vars(): void
    {
        // a one-parameter handler never receives the vars; whatever bag it returns would WIPE them
        $wf = new #[Workflow(name: 'blind')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            public function raise(object $signal): SignalResult
            {
                return SignalResult::stay([]);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/two arguments/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_signal_declarations_for_one_class(): void
    {
        $wf = new #[Workflow(name: 'dup')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        #[Signal(signal: SampleEvent::class, handler: 'raiseAgain')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function raiseAgain(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Two #\[Signal\] attributes claim/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_method_that_does_not_exist(): void
    {
        $wf = new #[Workflow(name: 'ghost')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'missing')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/method "missing"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_without_the_signal_result_return(): void
    {
        // the executor invokes the handler blind and persists ->vars from the result: a handler
        // returning anything else would explode mid-step, refused at build instead
        $wf = new #[Workflow(name: 'badret')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             * @return array<string, mixed>
             */
            public function raise(object $signal, array $vars): array
            {
                return $vars;
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/return type SignalResult/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_signal_handler_demanding_a_third_required_argument(): void
    {
        // invoked with exactly (signal, vars); a third REQUIRED parameter can never be satisfied
        $wf = new #[Workflow(name: 'arity')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars, string $mustHave): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/one demanding more can never be called/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_unknown_signal_class(): void
    {
        $wf = new #[Workflow(name: 'ghostclass')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: 'App\Ghost\NoSuchSignal', handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_interface_signal_can_never_match_and_is_rejected(): void
    {
        // the runtime lookup is by the incoming object's EXACT class; an interface key would sit
        // in the map forever unmatched, so the declaration is dead config. The refusal names the
        // exact-class rule, never "does not exist": the interface exists, and a typo diagnostic
        // would send its author hunting a misspelling instead of reading the rule
        $wf = new #[Workflow(name: 'iface')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: SettlementContract::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/an interface or abstract class/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_abstract_signal_class(): void
    {
        $wf = new #[Workflow(name: 'abs')]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'a', trigger: 'success', to: 'done')]
        #[Signal(signal: AbstractSettlement::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/abstract class/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function an_extract_and_a_matcher_coexist_on_one_wait(): void
    {
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class, matcher: 'accepts', extract: 'pull')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function accepts(object $event, array $vars): bool
            {
                return true;
            }

            /**
             * @param  array<string, mixed>  $vars
             * @return array<string, mixed>
             */
            public function pull(object $event, array $vars): array
            {
                return ['x' => 1];
            }
        };

        $w = $this->builder()->build($wf)->state('w');

        $this->assertInstanceOf(WaitState::class, $w);
        $this->assertNotNull($w->matcher);
        $this->assertNotNull($w->extract);
    }

    // --- build-validation gaps surfaced by mutation testing ---

    #[Test]
    public function builds_every_per_state_decorator_when_several_states_declare_them(): void
    {
        // the *-byState maps must keep ALL entries, not just the first; the SECOND state's retry /
        // breaker / compensation, and the second wait, must all survive the build.
        $wf = new #[Workflow(name: 'ok', globalTimeout: 3600)]
        #[State(key: 'a', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'b', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'w1', type: 'wait')]
        #[State(key: 'w2', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w1', events: SampleEvent::class)]
        #[WaitFor(state: 'w2', events: SampleEvent::class)]
        #[On(from: 'a', trigger: 'success', to: 'b')]
        #[On(from: 'b', trigger: 'success', to: 'done')]
        #[On(from: 'w1', trigger: 'event', to: 'done')]
        #[On(from: 'w2', trigger: 'event', to: 'done')]
        #[Retry(state: 'a', maxAttempts: 3)]
        #[Retry(state: 'b', maxAttempts: 5)]
        #[CircuitBreaker(state: 'a', resource: 'gw-a')]
        #[CircuitBreaker(state: 'b', resource: 'gw-b')]
        #[Compensate(state: 'a', activity: RecordingActivity::class)]
        #[Compensate(state: 'b', activity: RecordingActivity::class)]
        class {};

        $def = $this->builder()->build($wf);
        $b = $def->state('b');

        $this->assertInstanceOf(ActivityState::class, $b);
        $this->assertSame(5, $b->retry?->maxAttempts);     // the 2nd retry survived the map
        $this->assertNotNull($b->circuitBreaker);          // the 2nd breaker survived
        $this->assertNotNull($b->compensation);            // the 2nd compensation survived
        $this->assertInstanceOf(WaitState::class, $def->state('w2')); // the 2nd wait kept its #[WaitFor]
    }

    #[Test]
    public function accepts_a_per_state_timeout_strictly_below_the_global(): void
    {
        // Positive baseline: a per-state timeout < global is the supported case, the typical setup.
        $wf = new #[Workflow(name: 'ok', globalTimeout: 60, onGlobalTimeout: 'failed')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'failed')]
        class {};

        $def = $this->builder()->build($wf);

        self::assertSame('ok', $def->name);
    }

    #[Test]
    public function accepts_any_per_state_timeout_when_no_global_is_set(): void
    {
        // No global, no constraint. Per-state lives on its own deadline.
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 3600, onDeadline: 'failed')]
        class {};

        $def = $this->builder()->build($wf);

        self::assertSame('ok', $def->name);
    }

    #[Test]
    public function accepts_an_on_global_timeout_reachable_through_an_async_activity(): void
    {
        // predicate a: the only wait gates, being the activity's success-target, but the activity can
        // go async since it declares a timeout, so it is a non-gating resting point where the global
        // deadline drives onGlobalTimeout. Reachable, so accepted; the minimal PaymentWorkflow shape.
        $wf = new #[Workflow(name: 'ok', globalTimeout: 60, onGlobalTimeout: 'failed')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 30)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    public function accepts_a_global_timeout_without_a_target_as_a_bare_cap(): void
    {
        // a globalTimeout with NO onGlobalTimeout is a bare cap; fine even over only-gating waits. At the
        // gating wait it bounds the saga via HaltAtGlobalCap, there is no target to reach.
        $wf = new #[Workflow(name: 'ok', globalTimeout: 60)]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        self::assertSame('ok', $this->builder()->build($wf)->name);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_on_global_timeout_unreachable_through_only_gating_waits(): void
    {
        // the case: the only wait gates, charge's success-target, and the activity is sync with no
        // timeout, so no non-gating resting point exists and onGlobalTimeout is structurally unreachable
        $wf = new #[Workflow(name: 'bad', globalTimeout: 60, onGlobalTimeout: 'failed')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'failed')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/onGlobalTimeout.*unreachable/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_on_global_timeout_without_a_global_timeout(): void
    {
        // a target with no deadline to fire it; dead config, the timer is never armed
        $wf = new #[Workflow(name: 'bad', onGlobalTimeout: 'failed')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'failed', type: 'final')]
        #[WaitFor(state: 'w', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/no globalTimeout/');
        $this->builder()->build($wf);
    }

    // wait deadlines: heartbeat escalates vs deadline finalizes

    #[Test]
    public function a_deadline_wait_synthesises_a_timeout_transition_to_its_target(): void
    {
        // deadlineSeconds + onDeadline desugar into a timeout edge; the engine finalizes through the
        // same On(timeout) machinery as any other transition
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'expired')]
        class {};

        $w = $this->builder()->build($wf)->state('w');

        $this->assertInstanceOf(WaitState::class, $w);
        $this->assertSame(30, $w->timeout?->seconds);
        $this->assertCount(2, $w->transitions); // the declared On(event) edge survives, the timeout edge is appended
        $timeoutEdges = array_values(array_filter($w->transitions, static fn ($t): bool => $t->trigger === OnTrigger::Timeout));
        $this->assertCount(1, $timeoutEdges);
        $this->assertSame('expired', $timeoutEdges[0]->to);
    }

    #[Test]
    public function a_heartbeat_wait_arms_its_timer_without_a_finalise_edge(): void
    {
        // a heartbeat re-arms + escalates; it synthesizes no timeout transition, nothing to finalize to
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        $await = $this->builder()->build($wf)->state('await');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertSame(30, $await->timeout?->seconds);
        $this->assertSame([], array_filter($await->transitions, static fn ($t): bool => $t->trigger === OnTrigger::Timeout));
    }

    #[Test]
    public function a_declared_retriable_wait_reaches_the_built_state(): void
    {
        // the declarative path of the knob StepPolicy routes the global cap on: every other test of
        // retriable constructs its WaitState by hand, so a dropped pass-through in the assembler
        // would fail nothing while building every declared retriable wait as halt-at-cap
        $wf = new #[Workflow(name: 'ok')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30, retriable: true)]
        class {};

        $await = $this->builder()->build($wf)->state('await');

        $this->assertInstanceOf(WaitState::class, $await);
        $this->assertTrue($await->retriable);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retriable_wait_that_is_not_a_heartbeat_wait_through_the_builder(): void
    {
        // the rule's declarative door: a deadline wait finalizes, so retriable would silently do
        // nothing; the refusal must fire from a declared workflow, not only at the rules' own boundary
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'expired', retriable: true)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/retriable: true but is not a heartbeat wait/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_whose_every_route_for_an_accepted_event_is_guarded_through_the_builder(): void
    {
        // the rule's declarative door: an accepted event whose every candidate edge is guarded can
        // be matched, consumed, and route nowhere when every guard rejects
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done', onEvent: SampleEvent::class, guard: 'onlySometimes')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'expired')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function onlySometimes(array $vars): bool
            {
                return false;
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/keeps no unguarded route/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_wait_with_both_a_heartbeat_and_a_deadline(): void
    {
        // a wait pings to escalate or expires to finalize, not both
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 30, deadlineSeconds: 60, onDeadline: 'expired')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/both heartbeatSeconds and deadlineSeconds/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_deadline_without_a_target(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/deadlineSeconds without onDeadline/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_on_deadline_without_a_deadline(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, onDeadline: 'expired')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/onDeadline without deadlineSeconds/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_heartbeat_on_a_non_gating_wait(): void
    {
        // a heartbeat only escalates an in-flight effect; on a wait reached by an event it would arm a
        // timer with no finalize edge and silently halt; the soft-SLA case is a future extension.
        // `done` is declared first so a skipped state precedes the offending wait; the scan must
        // continue past it, not break.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'w', type: 'wait')]
        #[Start(state: 'w')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/heartbeatSeconds but is not effect-gating/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_on_deadline_naming_an_unknown_state(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'w', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'ghost')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/finalises to an undeclared state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_deadline_on_an_effect_gating_wait(): void
    {
        // the money invariant: a deadline finalizes, and an effect-gating wait may
        // never finalize an in-flight effect; the desugared timeout edge is caught by the engine-owns guard
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'expired', type: 'final')]
        #[On(from: 'charge', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'expired')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'expired')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/gates an issued effect/');
        $this->builder()->build($wf);
    }
}
