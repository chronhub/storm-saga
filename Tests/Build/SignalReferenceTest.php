<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Engine\Plan\ApplyUserSignal;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\SignalResult;

final class SignalReferenceTest extends TestCase
{
    #[Test]
    public function rejects_vars_passed_by_reference(): void
    {
        $wf = new #[Workflow(name: 'reference-proof', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: [stdClass::class])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object $signal, array &$vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };
        $this->expectException(InvalidWorkflowDefinition::class);
        new WorkflowBuilder(new ArrayContainer([]))->build($wf);
    }

    #[Test]
    public function value_handler_accepts_engine_arguments(): void
    {
        $wf = new #[Workflow(name: 'reference-proof', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: [stdClass::class])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
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
        $plan = new ApplyUserSignal(new SampleEvent);
        $row = new WorkflowInstanceRow('reference-proof', 'id', 'w', WorkflowStatus::Running, ['kept' => 1]);
        $handler = new WorkflowBuilder(new ArrayContainer([]))->build($wf)->signalHandlerFor($plan->signal);
        self::assertNotNull($handler);
        self::assertSame(['kept' => 1], $handler($plan->signal, $row->vars)->vars);
    }

    #[Test]
    public function signal_reference_accepts_engine_arguments(): void
    {
        $wf = new #[Workflow(name: 'reference-proof', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: [stdClass::class])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            /**
             * @param  array<string, mixed>  $vars
             */
            public function raise(object &$signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };
        $plan = new ApplyUserSignal(new SampleEvent);
        $row = new WorkflowInstanceRow('reference-proof', 'id', 'w', WorkflowStatus::Running, ['kept' => 1]);
        $handler = new WorkflowBuilder(new ArrayContainer([]))->build($wf)->signalHandlerFor($plan->signal);
        self::assertNotNull($handler);
        self::assertSame(['kept' => 1], $handler($plan->signal, $row->vars)->vars);
    }
}
