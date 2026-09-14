<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\SpawnSlot;
use Storm\Saga\Workflow\WorkflowDefinition;

final class WorkflowDefinitionTest extends TestCase
{
    #[Test]
    public function a_cycle_in_one_branch_does_not_hide_an_event_in_another_branch(): void
    {
        $start = new ActivityState('start', new RecordingActivity(ActivityResult::success()), transitions: [
            new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Success, 'target'),
            new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Failure, 'cycle'),
        ]);
        $cycle = new \Storm\Saga\Workflow\WaitState('cycle', transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Event, 'cycle')]);
        $target = new \Storm\Saga\Workflow\WaitState('target', eventClasses: [stdClass::class]);
        $definition = new WorkflowDefinition('branches', ['start' => $start, 'cycle' => $cycle, 'target' => $target], 'start');
        $this->assertTrue($definition->canStillAccept('start', stdClass::class));
    }

    #[Test]
    public function reachability_terminates_on_a_cycle_without_discarding_a_reachable_event(): void
    {
        $wait = new \Storm\Saga\Workflow\WaitState(
            'await',
            eventClasses: [stdClass::class],
            transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Event, 'await')],
        );
        $definition = new WorkflowDefinition('cycle', ['await' => $wait], 'await');
        $this->assertFalse($definition->canStillAccept('await', RuntimeException::class));
        $this->assertTrue($definition->canStillAccept('await', stdClass::class));
        $this->assertFalse($definition->canStillAccept('unknown', stdClass::class));
    }

    #[Test]
    public function exposes_its_states_by_key(): void
    {
        $charge = new ActivityState('charge', new RecordingActivity(ActivityResult::success()));
        $done = new FinalState('done');

        $def = new WorkflowDefinition('payment', ['charge' => $charge, 'done' => $done], start: 'charge', globalTimeout: 3600);

        $this->assertSame('payment', $def->name);
        $this->assertSame('charge', $def->start);
        $this->assertSame(3600, $def->globalTimeout);
        $this->assertSame(1, $def->version); // default schema version; pins the constructor default
        // its sibling, and the one a migration reads: an undeclared state bag is version 1, so a
        // workflow that never versioned its vars is not mistaken for one that has migrated once
        $this->assertSame(1, $def->stateVersion);
        $this->assertTrue($def->hasState('charge'));
        $this->assertSame($charge, $def->state('charge'));
        $this->assertSame(['charge' => $charge, 'done' => $done], $def->states());
    }

    #[Test]
    public function a_spawn_slot_consents_to_one_child_unless_it_says_otherwise(): void
    {
        // Built from attributes the flag is always passed explicitly, so only a construction WITHOUT
        // it reads the default. It is the safe one: a slot that silently defaulted to a family would
        // hand compile-time consent to a whole `slot-<i>` range nobody declared.
        $slot = new SpawnSlot('kyc', 'kyc_review', 'await_child');

        $this->assertFalse($slot->indexed);
    }

    #[Test]
    public function throws_for_an_unknown_state(): void
    {
        $def = new WorkflowDefinition('payment', ['done' => new FinalState], start: 'done');

        $this->assertFalse($def->hasState('charge'));

        $this->expectException(UnknownState::class);
        $this->expectExceptionMessageIsOrContains('Unknown state "charge" in workflow "payment".');
        $def->state('charge');
    }
}
