<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\EffectGating;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;

final class EffectGatingTest extends TestCase
{
    #[Test]
    public function a_state_reached_by_an_activity_success_transition_gates(): void
    {
        $charge = new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await_charge')]);

        $states = ['charge' => $charge, 'await_charge' => new WaitState('await_charge')];

        $this->assertTrue(EffectGating::gates($states, 'await_charge'));
    }

    #[Test]
    public function a_state_no_activity_targets_on_success_does_not_gate(): void
    {
        $charge = new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await_charge')]);

        $states = ['charge' => $charge, 'await_charge' => new WaitState('await_charge'), 'other' => new WaitState('other')];

        $this->assertFalse(EffectGating::gates($states, 'other'));   // no activity points to it on success
        $this->assertFalse(EffectGating::gates($states, 'missing')); // not a state at all
    }

    #[Test]
    public function only_a_success_trigger_gates_not_an_event(): void
    {
        // a wait reached via an EVENT, not a success-from-activity, gates no in-flight effect
        $awaitCharge = new WaitState('await_charge', transitions: [new Transition(OnTrigger::Event, 'await_next')]);

        $states = ['await_charge' => $awaitCharge, 'await_next' => new WaitState('await_next')];

        $this->assertFalse(EffectGating::gates($states, 'await_next'));
    }

    #[Test]
    public function gated_by_names_the_specific_issuing_activity(): void
    {
        // the settle's pairing predicate: gates() answers "gated by anyone", gatedBy "gated by THAT one"
        $charge = new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await_charge')]);
        $other = new ActivityState('other', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'elsewhere')]);

        $states = ['charge' => $charge, 'other' => $other, 'await_charge' => new WaitState('await_charge'), 'elsewhere' => new WaitState('elsewhere')];

        $this->assertTrue(EffectGating::gatedBy($states, 'await_charge', 'charge'));
        $this->assertFalse(EffectGating::gatedBy($states, 'await_charge', 'other'));   // gates elsewhere, not here
        $this->assertFalse(EffectGating::gatedBy($states, 'await_charge', 'missing')); // not a state at all
    }

    #[Test]
    public function a_non_success_edge_to_elsewhere_never_makes_an_activity_a_gater(): void
    {
        // an activity whose ONLY edge is a failure-route to another state gates nothing: neither the
        // trigger, which is not success, nor the target, which is not the wait, qualifies; and neither alone may
        $failing = new ActivityState('failing', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Failure, 'dead')]);

        $states = ['failing' => $failing, 'await' => new WaitState('await'), 'dead' => new WaitState('dead')];

        $this->assertFalse(EffectGating::gatedBy($states, 'await', 'failing'));
    }
}
