<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\AlphaCommand;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\BetaCommand;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class JoinMatcherReferenceTest extends TestCase
{
    #[Test]
    public function accepted_reference_matcher_records_partial_arrival_without_mutating_vars(): void
    {
        $def = $this->definition(true);
        $row = new WorkflowInstanceRow('join-reference', 'id', 'await', WorkflowStatus::Running, ['kept' => 1]);
        $extraction = new WaitVarExtractor($this->createStub(EventResolver::class));
        $wait = $def->state('await');
        self::assertInstanceOf(WaitState::class, $wait);
        self::assertTrue($extraction->matches($wait, new SampleEvent, $row->vars));
        self::assertSame(['kept' => 1], $row->vars);
        $gate = new JoinSettler(new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->createStub(WorkflowCommandStore::class)), FrozenClock::at('2026-01-01T00:00:00.000000Z'), $extraction);
        $result = $gate->gateArrival($def, $row, Stimulus::event(new SampleEvent));
        self::assertInstanceOf(Rested::class, $result);
        self::assertSame(['alpha'], $result->row->arms['quote']);
        self::assertSame(['kept' => 1], $result->row->vars);
        self::assertSame(['kept' => 1], $row->vars);
    }

    #[Test]
    public function rejected_reference_matcher_leaves_arrival_and_vars_untouched(): void
    {
        $def = $this->definition(false);
        $row = new WorkflowInstanceRow('join-reference', 'id', 'await', WorkflowStatus::Running, ['kept' => 1]);
        $extraction = new WaitVarExtractor($this->createStub(EventResolver::class));
        $wait = $def->state('await');
        self::assertInstanceOf(WaitState::class, $wait);
        self::assertFalse($extraction->matches($wait, new SampleEvent, $row->vars));
        $gate = new JoinSettler(new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->createStub(WorkflowCommandStore::class)), FrozenClock::at('2026-01-01T00:00:00.000000Z'), $extraction);
        self::assertNull($gate->gateArrival($def, $row, Stimulus::event(new SampleEvent)));
        self::assertSame([], $row->arms);
        self::assertSame(['kept' => 1], $row->vars);
    }

    private function definition(bool $accept): WorkflowDefinition
    {
        $workflow = new #[Workflow(name: 'join-reference')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'quote', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], matcher: 'matches', heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class($accept)
        {
            public function __construct(private readonly bool $accept) {}

            /**
             * @param  array<string, mixed>  $vars
             */
            public function matches(object $event, array &$vars): bool
            {
                $vars['kept'] = 99;

                return $this->accept;
            }
        };

        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]))->build($workflow);
    }
}
