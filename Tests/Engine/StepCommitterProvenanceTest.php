<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Signal;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Testing\InMemory\InMemorySagaState;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowCommands;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowInstances;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowTimers;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Testing\Fixture\ReserveInventory;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Serializer\DefaultMessageSerializer;

final class StepCommitterProvenanceTest extends TestCase
{
    #[Test]
    public function in_memory_advances_separate_revisits_and_refuse_a_stale_step_before_its_effects(): void
    {
        $state = new InMemorySagaState;
        $clock = FrozenClock::at('2026-09-27T00:00:00.000000+00:00');
        $instances = new InMemoryWorkflowInstances($state, $clock);
        $timers = new InMemoryWorkflowTimers($state, $clock);
        $commands = new InMemoryWorkflowCommands($state, new DefaultMessageSerializer, $clock);
        $committer = new StepCommitter($instances, $instances, $timers, new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $commands), $clock);
        $definition = new WorkflowDefinition('loop', [
            'await' => new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'await')]),
        ], 'await');
        $row = new WorkflowInstanceRow('loop', 'l-1', 'await', WorkflowStatus::Running);
        $issued = [new IssuedCommand('issue', new ReserveInventory('l-1'), CommandPurpose::Forward)];
        $committer->created(new WorkflowRegistry([$definition]), $definition, $row->id(), new Created($row, [], $issued), Signal::start());

        for ($version = 1; $version <= 2; $version++) {
            $loaded = $instances->find($row->id());
            $this->assertNotNull($loaded);
            $committer->updated($definition, $row->id(), new Updated($loaded, [], $issued), Signal::event(new SampleEvent));
            $this->assertSame($version, $instances->find($row->id())?->version);
        }

        $this->assertSame([0, 1, 2], array_column($state->commands, 'issuedAtVersion'));
        $latest = array_values($state->commands)[2];
        $messageId = $latest['header']['__message_id'];
        $this->assertTrue($commands->markPublished($messageId));
        $this->assertTrue($commands->markFailed('l-1', $messageId, 'rolled back', EffectEvidence::Uncommitted));
        $provenance = $commands->provenance('l-1', $messageId, 1);
        $this->assertNotNull($provenance);
        $this->assertFalse($provenance->hasAliveSiblings);
        $this->assertSame(EffectEvidence::Uncommitted, $provenance->evidence);

        $this->expectException(StaleWorkflowInstance::class);
        try {
            $committer->updated($definition, $row->id(), new Updated($loaded, [TimerOp::armTimeout('await', 60)], $issued), Signal::event(new SampleEvent));
        } finally {
            $this->assertSame([0, 1, 2], array_column($state->commands, 'issuedAtVersion'));
            $this->assertSame([], $state->timers);
        }
    }
}
