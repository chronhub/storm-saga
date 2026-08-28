<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\State;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Noop;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition as Edge;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class WaitRunnerTest extends TestCase
{
    #[Test]
    public function a_matching_event_by_class_transitions_without_polluting_vars(): void
    {
        // no extract declared, so the matched event leaves vars untouched, no schemaless last_event bag.
        $state = new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(payload: ['amount' => 10]));

        $verdict = $runner->run($this->ctx($state, event: new SampleEvent, vars: ['order_id' => 'ord-1', 'amount' => 10]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('next', $verdict->to);
        $this->assertSame(OnTrigger::Event, $verdict->trigger);
        $this->assertSame(['order_id' => 'ord-1', 'amount' => 10], $verdict->vars); // passed through unchanged and complete
        $this->assertArrayNotHasKey('last_event', $verdict->vars);
    }

    #[Test]
    public function a_matched_event_is_routed_to_a_different_state_by_its_class(): void
    {
        // one wait, two outcomes by event class; the success/failure pattern in the small
        $state = new WaitState('await', eventClasses: [SampleEvent::class, stdClass::class], transitions: [new Edge(OnTrigger::Event, 'paid', onEvent: SampleEvent::class), new Edge(OnTrigger::Event, 'declined', onEvent: stdClass::class)]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $paid = $runner->run($this->ctx($state, event: new SampleEvent));
        $declined = $runner->run($this->ctx($state, event: new stdClass));

        $this->assertInstanceOf(Transition::class, $paid);
        $this->assertSame('paid', $paid->to);
        $this->assertInstanceOf(Transition::class, $declined);
        $this->assertSame('declined', $declined->to);
    }

    #[Test]
    public function a_matching_event_by_alias_transitions(): void
    {
        $state = new WaitState('await', eventTypes: ['order.placed'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(alias: 'order.placed'));

        $verdict = $runner->run($this->ctx($state, event: new SampleEvent));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('next', $verdict->to);
    }

    #[Test]
    public function a_rejecting_matcher_means_no_match(): void
    {
        $state = new WaitState('await', eventClasses: [SampleEvent::class], matcher: static fn (object $e, array $v): bool => false, transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $this->assertInstanceOf(Noop::class, $runner->run($this->ctx($state, event: new SampleEvent)));
    }

    #[Test]
    public function an_event_whose_alias_is_not_declared_does_not_match(): void
    {
        // an alias the wait did not declare must NOT match; guards against both a true-by-default and an "any alias slips through"
        $state = new WaitState('await', eventTypes: ['order.placed'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(alias: 'order.shipped')); // not 'order.placed'

        $this->assertInstanceOf(Noop::class, $runner->run($this->ctx($state, event: new SampleEvent)));
    }

    #[Test]
    public function a_class_match_is_not_undone_by_a_declared_alias_list(): void
    {
        // matched by class already, so the alias list is not consulted; a `||` on the guard would re-check and drop it
        $state = new WaitState('await', eventClasses: [SampleEvent::class], eventTypes: ['order.placed'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(alias: 'order.shipped')); // alias NOT in eventTypes

        $verdict = $runner->run($this->ctx($state, event: new SampleEvent));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('next', $verdict->to);
    }

    #[Test]
    public function entering_a_wait_with_a_timeout_arms_the_timer(): void
    {
        $state = new WaitState('await', eventClasses: [SampleEvent::class], timeout: new Timeout(60), transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $verdict = $runner->run($this->ctx($state)); // no event yet

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertCount(1, $verdict->timerOps);
        $job = $verdict->timerOps[0];
        $this->assertSame(TimerOpKind::ArmTimeout, $job->kind);
        $this->assertSame('await', $job->stateKey);
        $this->assertSame(60, $job->seconds);
    }

    #[Test]
    public function entering_a_wait_with_a_business_deadline_arms_a_business_timeout(): void
    {
        // a business-time deadline means the shell resolves it via the BusinessCalendar, not now + seconds
        $state = new WaitState('await', eventClasses: [SampleEvent::class], timeout: new Timeout(0, businessDays: 2, businessHours: 4), transitions: [new Edge(OnTrigger::Timeout, 'expired')]);

        $verdict = new WaitRunner(new TransitionSelector, new StubEventResolver)->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $job = $verdict->timerOps[0];
        $this->assertSame(TimerOpKind::ArmTimeout, $job->kind);
        $this->assertNull($job->seconds);         // no wall-clock seconds for a business deadline
        $this->assertSame(2, $job->businessDays);
        $this->assertSame(4, $job->businessHours);
    }

    #[Test]
    public function a_wait_with_no_event_and_no_timeout_is_a_noop(): void
    {
        $state = new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Edge(OnTrigger::Event, 'next')]);

        $verdict = new WaitRunner(new TransitionSelector, new StubEventResolver)->run($this->ctx($state));

        $this->assertInstanceOf(Noop::class, $verdict);
    }

    #[Test]
    public function a_fired_timeout_takes_the_timeout_transition(): void
    {
        $state = new WaitState('await', transitions: [new Edge(OnTrigger::Timeout, 'expired')]);

        $verdict = new WaitRunner(new TransitionSelector, new StubEventResolver)->run($this->ctx($state, isTimeout: true));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('expired', $verdict->to);
        $this->assertSame(OnTrigger::Timeout, $verdict->trigger);
    }

    #[Test]
    public function a_fired_timeout_with_no_transition_halts(): void
    {
        $state = new WaitState('await'); // no transitions declared

        $verdict = new WaitRunner(new TransitionSelector, new StubEventResolver)->run($this->ctx($state, isTimeout: true));

        $this->assertInstanceOf(Halt::class, $verdict);
    }

    #[Test]
    public function a_method_form_extract_merges_named_keys_into_vars(): void
    {
        $state = new WaitState('await', eventClasses: [SampleEvent::class], extract: static fn (object $e, array $v): array => ['captured_cents' => 19166], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        // a prior var must survive the merge; extract ADDS, it does not replace the bag
        $verdict = $runner->run($this->ctx($state, event: new SampleEvent, vars: ['order_id' => 'ord-1']));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(['order_id' => 'ord-1', 'captured_cents' => 19166], $verdict->vars);
    }

    #[Test]
    public function a_map_form_extract_pulls_payload_fields_into_named_vars(): void
    {
        $state = new WaitState('await', eventClasses: [SampleEvent::class], extractMap: ['captured_cents' => 'amount'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(payload: ['amount' => 19166]));

        // the pulled field is added alongside the prior vars, the full bag, not just the new key
        $verdict = $runner->run($this->ctx($state, event: new SampleEvent, vars: ['order_id' => 'ord-1']));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(['order_id' => 'ord-1', 'captured_cents' => 19166], $verdict->vars);
    }

    #[Test]
    public function an_extracted_key_overrides_an_existing_var(): void
    {
        // collision policy: a declared extract is explicit intent, so it wins over a pre-existing var.
        $state = new WaitState('await', eventClasses: [SampleEvent::class], extractMap: ['cents' => 'amount'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(payload: ['amount' => 19166]));

        $verdict = $runner->run($this->ctx($state, event: new SampleEvent, vars: ['cents' => 1]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(19166, $verdict->vars['cents']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_map_form_extract_throws_when_a_declared_field_is_absent(): void
    {
        // the matcher already accepted the event, so a declared field missing from its payload is a wiring
        // bug, surfaced loud, never a silent null in vars.
        $state = new WaitState('await', eventClasses: [SampleEvent::class], extractMap: ['captured_cents' => 'amount'], transitions: [new Edge(OnTrigger::Event, 'next')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver(payload: [])); // no 'amount'

        $this->expectException(MissingExtractField::class);
        $runner->run($this->ctx($state, event: new SampleEvent));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_wrong_state_subtype_halts_defensively(): void
    {
        // The MachineRunner dispatches by subtype before calling a runner directly; calling WaitRunner
        // with an ActivityState proves the guard returns Halt instead of dereferencing the wrong subtype.
        $state = new ActivityState('charge', new class() implements Activity
        {
            public function run(array $vars, Metadata $metadata): ActivityResult
            {
                return ActivityResult::success();
            }
        });
        $ctx = new StateContext(
            'wf',
            'corr-1',
            new WorkflowDefinition('wf', [$state->key => $state], $state->key),
            $state,
            Stimulus::none(),
            PointInTime::from('2026-01-01T00:00:00.000000Z'),
            anchor: PointInTime::from('2026-01-01T00:00:00.000000Z'),
        );

        $verdict = new WaitRunner(new TransitionSelector, new StubEventResolver)->run($ctx);
        $this->assertInstanceOf(Halt::class, $verdict);
    }

    #[Test]
    public function a_replayed_crossing_routes_by_its_class_without_matching_or_extracting_again(): void
    {
        // the crossing a family gate rested: it matched and extracted when the conclusion was
        // absorbed, so the replay carries a class and the vars the rest already landed. A matcher
        // that would now refuse must never be consulted, and an extract must not run twice.
        $state = new WaitState(
            'await',
            eventClasses: [SampleEvent::class, stdClass::class],
            matcher: static fn (object $event, array $vars): bool => throw new LogicException('a replayed crossing never re-matches'),
            extract: static fn (object $event, array $vars): array => throw new LogicException('a replayed crossing never re-extracts'),
            transitions: [new Edge(OnTrigger::Event, 'paid', onEvent: SampleEvent::class), new Edge(OnTrigger::Event, 'declined', onEvent: stdClass::class)],
        );
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $verdict = $runner->run($this->ctx($state, replayed: SampleEvent::class, vars: ['leg_seen' => true]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('paid', $verdict->to); // routed by the parked class, not by a catch-all
        $this->assertSame(OnTrigger::Event, $verdict->trigger);
        $this->assertSame(['leg_seen' => true], $verdict->vars); // the rest's landing, carried forward
    }

    #[Test]
    public function a_replayed_crossing_whose_class_routes_nowhere_halts(): void
    {
        // the pinned definition is what routes, and a shape that no longer carries the edge halts
        // loudly rather than resting on a wait it can never leave
        $state = new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Edge(OnTrigger::Event, 'paid', onEvent: stdClass::class)]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $this->assertInstanceOf(Halt::class, $runner->run($this->ctx($state, replayed: SampleEvent::class)));
    }

    #[Test]
    public function a_fired_timeout_outranks_a_replayed_crossing_on_the_same_run(): void
    {
        // defensive ordering, not a reachable plan: the two stimuli are built by different factories
        // and never coexist, and the timeout branch stays first so a deadline can never be shadowed
        $state = new WaitState('await', eventClasses: [SampleEvent::class], timeout: new Timeout(30), transitions: [new Edge(OnTrigger::Timeout, 'expired'), new Edge(OnTrigger::Event, 'paid')]);
        $runner = new WaitRunner(new TransitionSelector, new StubEventResolver);

        $verdict = $runner->run($this->ctx($state, isTimeout: true));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('expired', $verdict->to);
    }

    /**
     * @param  class-string|null  $replayed
     * @param  array<string, mixed>  $vars
     */
    private function ctx(WaitState $state, ?object $event = null, bool $isTimeout = false, array $vars = [], ?string $replayed = null): StateContext
    {
        $stimulus = match (true) {
            $event !== null => Stimulus::event($event),
            $isTimeout => Stimulus::timeout(),
            $replayed !== null => Stimulus::replay($replayed),
            default => Stimulus::none(),
        };

        return new StateContext('wf', 'corr-1', new WorkflowDefinition('wf', [$state->key => $state], $state->key), $state, $stimulus, PointInTime::from('2026-01-01T00:00:00.000000Z'), anchor: PointInTime::from('2026-01-01T00:00:00.000000Z'), vars: $vars);
    }
}
