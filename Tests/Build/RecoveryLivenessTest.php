<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\ActivityResult;

final class RecoveryLivenessTest extends TestCase
{
    #[Test]
    public function a_gating_recovery_wait_requires_its_own_heartbeat(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'result', type: 'wait')]
        #[On(from: 'recover', trigger: 'success', to: 'result')]
        #[On(from: 'result', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'result', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*heartbeatSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function a_non_gating_transitive_recovery_wait_requires_its_own_deadline(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'done')]
        #[On(from: 'recover', trigger: 'event', to: 'later')]
        #[State(key: 'later', type: 'wait')]
        #[WaitFor(state: 'later', events: SampleEvent::class)]
        #[On(from: 'later', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*deadlineSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function the_recovery_target_itself_requires_liveness(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class)]
        #[On(from: 'recover', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*deadlineSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function a_transitive_cycle_does_not_hide_a_clockless_wait(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'relay', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'result', type: 'wait')]
        #[On(from: 'recover', trigger: 'success', to: 'relay')]
        #[On(from: 'relay', trigger: 'success', to: 'result')]
        #[On(from: 'result', trigger: 'event', to: 'recover')]
        #[WaitFor(state: 'result', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*heartbeatSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function a_wait_outside_recovery_keeps_the_cap_exemption(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'final')]
        class {};

        $this->assertSame('recovery', $this->builder()->build($workflow)->name);
    }

    #[Test]
    public function a_recovery_heartbeat_is_accepted(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'result', type: 'wait')]
        #[On(from: 'recover', trigger: 'success', to: 'result')]
        #[On(from: 'result', trigger: 'event', to: 'done')]
        #[WaitFor(state: 'result', events: SampleEvent::class, heartbeatSeconds: 30)]
        class {};

        $this->assertSame('recovery', $this->builder()->build($workflow)->name);
    }

    #[Test]
    public function a_recovery_deadline_is_accepted(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'done')]
        #[On(from: 'recover', trigger: 'event', to: 'done')]
        class {};

        $this->assertSame('recovery', $this->builder()->build($workflow)->name);
    }

    #[Test]
    public function a_non_gating_child_wait_keeps_its_exemption_in_recovery(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[Spawns(slot: 'child', workflow: 'child', awaitedBy: 'recover')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class)]
        #[On(from: 'recover', trigger: 'event', to: 'done')]
        class {};

        $this->assertSame('recovery', $this->builder()->build($workflow)->name);
    }

    #[Test]
    public function a_gating_child_wait_still_requires_a_recovery_heartbeat(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'result', type: 'wait')]
        #[On(from: 'recover', trigger: 'success', to: 'result')]
        #[On(from: 'result', trigger: 'event', to: 'done')]
        #[Spawns(slot: 'child', workflow: 'child', awaitedBy: 'result')]
        #[WaitFor(state: 'result', events: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*heartbeatSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function a_return_to_the_main_graph_requires_liveness_there_too(): void
    {
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'done')]
        #[On(from: 'recover', trigger: 'event', to: 'await')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout.*already consumed.*deadlineSeconds/');
        $this->builder()->build($workflow);
    }

    #[Test]
    public function a_recovery_wait_behind_a_loop_back_to_the_target_is_still_reached(): void
    {
        // the walk from `recover` pushes `later`, then `loop`; `loop` expires back to the target, so it
        // comes off the stack a second time while `later` still sits under it. Stopping the walk there
        // would leave `later` outside the recovery and its missing deadline unseen.
        $workflow = new #[Workflow(name: 'recovery', globalTimeout: 60, onGlobalTimeout: 'recover')]
        #[Start(state: 'await')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'await', events: SampleEvent::class)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[State(key: 'recover', type: 'wait')]
        #[WaitFor(state: 'recover', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'loop')]
        #[On(from: 'recover', trigger: 'event', to: 'later')]
        #[State(key: 'loop', type: 'wait')]
        #[WaitFor(state: 'loop', events: SampleEvent::class, deadlineSeconds: 30, onDeadline: 'recover')]
        #[On(from: 'loop', trigger: 'event', to: 'done')]
        #[State(key: 'later', type: 'wait')]
        #[WaitFor(state: 'later', events: SampleEvent::class)]
        #[On(from: 'later', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/"later".*globalTimeout.*already consumed.*deadlineSeconds/');
        $this->builder()->build($workflow);
    }

    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }
}
