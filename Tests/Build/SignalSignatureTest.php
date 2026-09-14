<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use ArrayIterator;
use Countable;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBinder;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\SignalResult;
use Stringable;

final class SignalSignatureTest extends TestCase
{
    #[Test]
    public function rejects_vars_that_cannot_receive_an_array(): void
    {
        $wf = new #[Workflow(name: 'proof', globalTimeout: 3600)]
        #[State(key: 'w', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[WaitFor(state: 'w', events: [stdClass::class])]
        #[On(from: 'w', trigger: 'event', to: 'done')]
        #[Signal(signal: SampleEvent::class, handler: 'raise')]
        class
        {
            public function raise(object $signal, string $vars): SignalResult
            {
                return SignalResult::stay([]);
            }
        };
        $this->expectException(InvalidWorkflowDefinition::class);
        new WorkflowBuilder(new ArrayContainer([]))->build($wf);
    }

    #[Test]
    public function rejects_union_that_cannot_receive_the_signal(): void
    {
        $wf = new #[Workflow(name: 'proof', globalTimeout: 3600)]
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
            public function raise(int|string $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };
        $this->expectException(InvalidWorkflowDefinition::class);
        new WorkflowBuilder(new ArrayContainer([]))->build($wf);
    }

    #[Test]
    public function accepts_mixed_signal_as_an_object_supertype(): void
    {
        $wf = new #[Workflow(name: 'proof', globalTimeout: 3600)]
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
            public function raise(mixed $signal, array $vars): SignalResult
            {
                return SignalResult::stay($vars);
            }
        };
        try {
            $handler = new WorkflowBuilder(new ArrayContainer([]))->build($wf)->signalHandlerFor(new SampleEvent);
        } catch (InvalidWorkflowDefinition $e) {
            self::fail('Compatible handler rejected: '.$e->getMessage());
        }
        self::assertNotNull($handler);
        self::assertSame(['kept' => 1], $handler(new SampleEvent, ['kept' => 1])->vars);
    }

    #[Test]
    public function ordinary_handler_preserves_vars(): void
    {
        $wf = new #[Workflow(name: 'proof', globalTimeout: 3600)]
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
        try {
            $handler = new WorkflowBuilder(new ArrayContainer([]))->build($wf)->signalHandlerFor(new SampleEvent);
        } catch (InvalidWorkflowDefinition $e) {
            self::fail('Compatible handler rejected: '.$e->getMessage());
        }
        self::assertNotNull($handler);
        self::assertSame(['kept' => 1], $handler(new SampleEvent, ['kept' => 1])->vars);
    }

    #[Test]
    #[DataProvider('signatureCases')]
    public function checks_argument_compatibility(string $method, bool $accepted, bool $useHandlerAsSignal): void
    {
        $handlers = new class() extends ArrayIterator implements Stringable
        {
            public function __toString(): string
            {
                return 'signal';
            }

            public function __invoke(): void {}

            /**
             * @param  array<string, mixed>  $vars
             */
            public function union(int|self $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function intersection(Iterator&Countable $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function dnf((Iterator&Countable)|string $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function incompatibleIntersection(Iterator&Stringable $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  iterable<mixed>  $signal
             * @param  array<string, mixed>  $vars
             */
            public function iterableSignal(iterable $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function callableSignal(callable $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>  $vars
             */
            public function selfSignal(self $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  ArrayIterator<array-key, mixed>  $signal
             * @param  array<string, mixed>  $vars
             */
            public function parentSignal(parent $signal, array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  mixed  $signal
             * @param  mixed  $vars
             */
            public function untyped($signal, $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            public function mixedVars(object $signal, mixed $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  iterable<string, mixed>  $vars
             */
            public function iterableVars(object $signal, iterable $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>|string  $vars
             */
            public function unionVars(object $signal, array|string $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            public function incompatibleUnionVars(object $signal, int|string $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            public function intersectionVars(object $signal, Iterator&Countable $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            /**
             * @param  array<string, mixed>|null  $vars
             */
            public function nullableVars(object $signal, ?array $vars): SignalResult
            {
                return SignalResult::stay([]);
            }

            public function callableVars(object $signal, callable $vars): SignalResult
            {
                return SignalResult::stay([]);
            }
        };
        $signal = $useHandlerAsSignal ? $handlers : new ArrayIterator;
        if (! $accepted) {
            $this->expectException(InvalidWorkflowDefinition::class);
        }
        $handler = new WorkflowBinder(new ArrayContainer([]))->bindSignalHandler(
            new ReflectionClass($this->asObject($handlers)), new Signal(signal: $signal::class, handler: $method), $handlers, 'compatibility',
        );
        self::assertInstanceOf(SignalResult::class, $handler($signal, ['kept' => 1]));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function signatureCases(): iterable
    {
        yield 'union-0' => ['union', true, true];
        yield 'union-1' => ['union', false, false];
        yield 'intersection-2' => ['intersection', true, false];
        yield 'dnf-3' => ['dnf', true, false];
        yield 'incompatibleIntersection-4' => ['incompatibleIntersection', false, false];
        yield 'incompatibleIntersection-5' => ['incompatibleIntersection', true, true];
        yield 'iterableSignal-6' => ['iterableSignal', true, false];
        yield 'callableSignal-7' => ['callableSignal', true, true];
        yield 'callableSignal-8' => ['callableSignal', false, false];
        yield 'selfSignal-9' => ['selfSignal', true, true];
        yield 'selfSignal-10' => ['selfSignal', false, false];
        yield 'parentSignal-11' => ['parentSignal', true, false];
        yield 'untyped-12' => ['untyped', true, false];
        yield 'mixedVars-13' => ['mixedVars', true, false];
        yield 'iterableVars-14' => ['iterableVars', true, false];
        yield 'unionVars-15' => ['unionVars', true, false];
        yield 'incompatibleUnionVars-16' => ['incompatibleUnionVars', false, false];
        yield 'intersectionVars-17' => ['intersectionVars', false, false];
        yield 'nullableVars-18' => ['nullableVars', true, false];
        yield 'callableVars-19' => ['callableVars', false, false];
    }

    private function asObject(object $value): object
    {
        return $value;
    }
}
