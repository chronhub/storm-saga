<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Signal;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Exception\WorkflowStateRejected;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\WorkflowDefinition;

final class StateValidatorReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function writers(): iterable
    {
        yield 'birth' => ['created'];
        yield 'advance' => ['updated'];
        yield 'migration' => ['migration'];
    }

    #[Test]
    #[DataProvider('writers')]
    public function reference_validator_allows_write_without_changing_persisted_vars(string $writer): void
    {
        $row = new WorkflowInstanceRow('validator-reference', 'id', 'wait', WorkflowStatus::Running, ['kept' => 1]);
        $instances = $this->createMock(WorkflowInstances::class);
        $method = $writer === 'created' ? 'create' : 'update';
        $expectation = $instances->expects(self::once())->method($method)->with(self::callback(
            static fn (WorkflowInstanceRow $written): bool => $written->vars === ['kept' => 1],
        ));
        if ($writer === 'created') {
            $expectation->willReturn(1);
        }
        $this->persist($writer, $this->committer($instances), $this->definition(), $row);
        self::assertSame(['kept' => 1], $row->vars);
    }

    #[Test]
    #[DataProvider('writers')]
    public function reference_validator_refusal_preserves_its_cause_and_prevents_write(string $writer): void
    {
        $row = new WorkflowInstanceRow('validator-reference', 'id', 'wait', WorkflowStatus::Running, ['kept' => 1]);
        $cause = new DomainException('invalid state');
        $instances = $this->createMock(WorkflowInstances::class);
        $instances->expects(self::never())->method('create');
        $instances->expects(self::never())->method('update');
        try {
            $this->persist($writer, $this->committer($instances), $this->definition($cause), $row);
            self::fail('Expected the declared validator to reject the state');
        } catch (WorkflowStateRejected $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }
        self::assertSame(['kept' => 1], $row->vars);
    }

    private function persist(string $writer, StepCommitter $committer, WorkflowDefinition $definition, WorkflowInstanceRow $row): void
    {
        $id = new WorkflowId($row->workflowType, $row->correlationId);
        if ($writer === 'created') {
            $committer->created(new WorkflowRegistry([$definition]), $definition, $id, new Created($row), Signal::start());
        } elseif ($writer === 'updated') {
            $committer->updated($definition, $id, new Updated($row), Signal::event(new SampleEvent));
        } else {
            $committer->migration($definition, $row);
        }
    }

    private function committer(WorkflowInstances $instances): StepCommitter
    {
        return new StepCommitter(
            $instances,
            $this->createStub(WorkflowFamilies::class),
            $this->createStub(WorkflowTimers::class),
            new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->createStub(WorkflowCommandStore::class)),
            FrozenClock::at('2026-01-01T00:00:00.000000Z'),
        );
    }

    private function definition(?DomainException $cause = null): WorkflowDefinition
    {
        $workflow = new #[Workflow(name: 'validator-reference', globalTimeout: 3600)]
        #[State(key: 'wait', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'wait', events: SampleEvent::class)]
        #[On(from: 'wait', trigger: 'event', to: 'done')]
        class($cause)
        {
            public function __construct(private readonly ?DomainException $cause) {}

            /**
             * @param  array<string, mixed>  $vars
             */
            public function validateState(array &$vars): void
            {
                $vars['kept'] = 99;
                if ($this->cause !== null) {
                    throw $this->cause;
                }
            }
        };

        return new WorkflowBuilder(new ArrayContainer([]))->build($workflow);
    }
}
