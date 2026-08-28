<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The reachability question the engine asks before discarding a delivered event: could an instance
 * resting HERE ever consume THIS class? A wrong `false` throws a business fact away, so the walk has
 * to be complete over every way a state key can move, and over-approximating everywhere else.
 */
final class CanStillAcceptTest extends TestCase
{
    #[Test]
    public function a_class_no_wait_declares_is_never_acceptable(): void
    {
        // the shared-class case: the framework's router subscribes to the union every #[WaitFor]
        // declares across ALL workflows, so an event this definition never awaits can still be routed
        // to one of its instances by correlation.
        $def = $this->linear();

        self::assertFalse($def->canStillAccept('await_charge', stdClass::class));
    }

    #[Test]
    public function a_wait_left_behind_is_no_longer_acceptable(): void
    {
        // the stale-duplicate case: the saga has moved past `await_charge` and, the graph being linear,
        // cannot return to it.
        $def = $this->linear();

        self::assertTrue($def->canStillAccept('charge', FirstEvent::class), 'still ahead of it');
        self::assertFalse($def->canStillAccept('await_ship', FirstEvent::class), 'left for good');
    }

    #[Test]
    public function the_resting_wait_itself_stays_acceptable(): void
    {
        // an event matching the current wait but declined by a guard is an EARLY arrival, not a dead
        // one: the state is included in its own walk.
        $def = $this->linear();

        self::assertTrue($def->canStillAccept('await_charge', FirstEvent::class));
    }

    #[Test]
    public function a_cycle_makes_a_passed_wait_acceptable_again(): void
    {
        // reachability, not history: a graph that loops back can consume the class a second time, so
        // "already passed" is not the question; "still reachable" is.
        $def = new WorkflowDefinition('loop', [
            'await_one' => new WaitState('await_one', eventClasses: [FirstEvent::class], transitions: [new Transition(OnTrigger::Event, 'await_two')]),
            'await_two' => new WaitState('await_two', eventClasses: [SecondEvent::class], transitions: [new Transition(OnTrigger::Event, 'await_one')]),
        ], 'await_one');

        self::assertTrue($def->canStillAccept('await_two', FirstEvent::class));
    }

    #[Test]
    public function the_global_timeout_target_is_reachable_from_anywhere(): void
    {
        // `onGlobalTimeout` is the one edge no state declares: the deadline fires wherever the saga is,
        // so a wait reachable only through it must still count. Missing this edge would discard events
        // the recovery branch is there to receive.
        $def = new WorkflowDefinition(
            'deadlined',
            [
                'await_charge' => new WaitState('await_charge', eventClasses: [FirstEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
                'recover' => new WaitState('recover', eventClasses: [SecondEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
                'done' => new FinalState('done'),
            ],
            'await_charge',
            globalTimeout: 60,
            onGlobalTimeout: 'recover',
        );

        self::assertTrue($def->canStillAccept('await_charge', SecondEvent::class));
    }

    #[Test]
    public function a_guarded_edge_counts_as_taken(): void
    {
        // over-approximation on purpose: guards read runtime vars, so a walk that evaluated them would
        // answer for THIS instant only. Counting the edge keeps `false` meaning "certainly never".
        $def = new WorkflowDefinition('guarded', [
            'gate' => new WaitState('gate', eventClasses: [FirstEvent::class], transitions: [
                new Transition(OnTrigger::Event, 'await_two', guard: static fn (array $vars): bool => false),
            ]),
            'await_two' => new WaitState('await_two', eventClasses: [SecondEvent::class]),
        ], 'gate');

        self::assertTrue($def->canStillAccept('gate', SecondEvent::class));
    }

    #[Test]
    public function a_terminal_state_accepts_nothing_further(): void
    {
        $def = $this->linear();

        self::assertFalse($def->canStillAccept('done', FirstEvent::class));
    }

    /**
     * The linear graph: `charge` succeeds into `await_charge`, which consumes `FirstEvent` into
     * `ship`, which succeeds into `await_ship`, which consumes `SecondEvent` into `done`.
     */
    private function linear(): WorkflowDefinition
    {
        return new WorkflowDefinition('linear', [
            'charge' => new ActivityState('charge', $this->ok(), transitions: [new Transition(OnTrigger::Success, 'await_charge')]),
            'await_charge' => new WaitState('await_charge', eventClasses: [FirstEvent::class], transitions: [new Transition(OnTrigger::Event, 'ship')]),
            'ship' => new ActivityState('ship', $this->ok(), transitions: [new Transition(OnTrigger::Success, 'await_ship')]),
            'await_ship' => new WaitState('await_ship', eventClasses: [SecondEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
        ], 'charge');
    }

    private function ok(): Activity
    {
        return new class() implements Activity
        {
            public function run(array $vars, Metadata $metadata): ActivityResult
            {
                return ActivityResult::success();
            }
        };
    }
}

/**
 * A bare awaited class: the walk only ever reads declared shapes, so this and its sibling below
 * name classes and nothing more.
 *
 * @see State
 */
final class FirstEvent {}

final class SecondEvent {}
