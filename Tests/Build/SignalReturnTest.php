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
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\SignalResult;

final class SignalReturnTest extends TestCase
{
    #[Test]
    public function rejects_nullable_return(): void
    {
        $wf = new #[Workflow(name: 'return-proof', globalTimeout: 3600)]
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
            public function raise(object $signal, array $vars): ?SignalResult
            {
                return $vars === [] ? null : SignalResult::stay($vars);
            }
        };
        $this->expectException(InvalidWorkflowDefinition::class);
        new WorkflowBuilder(new ArrayContainer([]))->build($wf);
    }

    #[Test]
    public function non_nullable_handler_preserves_vars(): void
    {
        $wf = new #[Workflow(name: 'return-proof', globalTimeout: 3600)]
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
        $handler = new WorkflowBuilder(new ArrayContainer([]))->build($wf)->signalHandlerFor(new SampleEvent);
        self::assertNotNull($handler);
        self::assertSame(['kept' => 1], $handler(new SampleEvent, ['kept' => 1])->vars);
    }
}
