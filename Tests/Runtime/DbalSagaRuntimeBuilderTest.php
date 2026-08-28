<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Runtime;

use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionProperty;
use Storm\Message\ContextValues;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\ConfiguredBusinessCalendar;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\FamilyGate;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Engine\MachineRunner;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Engine\StepExecutor;
use Storm\Saga\Engine\StepPerformer;
use Storm\Saga\Locking\Dbal\PgAdvisoryFence;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Runtime\Dbal\DbalSagaRuntimeBuilder;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\Dbal\DbalWorkflowTimerStore;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowTimerStore;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * The builder is pure composition, so its whole contract is judged here without a database: every
 * required capability refused loud when missing, the defaults it is allowed to construct, and the
 * one guarantee the wired deployment lives on, that the join and family collaborators carry the
 * extraction built from the GIVEN resolver, never a weaker stand-in.
 */
final class DbalSagaRuntimeBuilderTest extends TestCase
{
    #[Test]
    public function a_missing_connection_is_refused_loud(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('connection()');

        DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry([]))
            ->eventResolver(new StubEventResolver)
            ->clock(new MutableClock)
            ->events($this->createStub(EventDispatcherInterface::class))
            ->build();
    }

    #[Test]
    public function a_missing_event_resolver_is_refused_loud(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('eventResolver()');

        DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry([]))
            ->connection($this->createStub(Connection::class))
            ->clock(new MutableClock)
            ->events($this->createStub(EventDispatcherInterface::class))
            ->build();
    }

    #[Test]
    public function a_missing_clock_is_refused_loud(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('clock()');

        DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry([]))
            ->connection($this->createStub(Connection::class))
            ->eventResolver(new StubEventResolver)
            ->events($this->createStub(EventDispatcherInterface::class))
            ->build();
    }

    #[Test]
    public function a_missing_dispatcher_is_refused_loud(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('events()');

        DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry([]))
            ->connection($this->createStub(Connection::class))
            ->eventResolver(new StubEventResolver)
            ->clock(new MutableClock)
            ->build();
    }

    #[Test]
    public function the_default_graph_is_the_dbal_one_with_no_breaker_and_no_calendar(): void
    {
        $engine = $this->builder()->build();

        $executor = $this->grab($engine, Engine::class, 'executor');
        self::assertInstanceOf(StepExecutor::class, $executor);
        self::assertInstanceOf(PgAdvisoryFence::class, $this->grab($executor, StepExecutor::class, 'fence'));
        $committer = $this->grab($executor, StepExecutor::class, 'committer');
        self::assertInstanceOf(StepCommitter::class, $committer);
        self::assertInstanceOf(DbalWorkflowInstanceStore::class, $this->grab($committer, StepCommitter::class, 'instances'));
        self::assertInstanceOf(DbalWorkflowTimerStore::class, $this->grab($committer, StepCommitter::class, 'timers'));
        self::assertNull($this->grab($committer, StepCommitter::class, 'calendar'));

        $outbox = $this->grab($committer, StepCommitter::class, 'outbox');
        self::assertInstanceOf(WorkflowOutbox::class, $outbox);
        $protocol = $this->grab($outbox, WorkflowOutbox::class, 'protocol');
        self::assertInstanceOf(HopProtocol::class, $protocol);
        self::assertEquals(ContextValues::empty(), $this->grab($protocol, HopProtocol::class, 'context'));
        $writer = $this->grab($outbox, WorkflowOutbox::class, 'writer');
        self::assertInstanceOf(DbalWorkflowOutboxWriter::class, $writer);
        self::assertInstanceOf(DefaultMessageSerializer::class, $this->grab($writer, DbalWorkflowOutboxWriter::class, 'serializer'));

        $performer = $this->grab($executor, StepExecutor::class, 'performer');
        self::assertInstanceOf(StepPerformer::class, $performer);
        $machine = $this->grab($performer, StepPerformer::class, 'machine');
        self::assertInstanceOf(MachineRunner::class, $machine);
        $activity = $this->grab($machine, MachineRunner::class, 'activity');
        self::assertInstanceOf(ActivityRunner::class, $activity);
        self::assertNull($this->grab($activity, ActivityRunner::class, 'breaker'));
    }

    #[Test]
    public function the_join_and_family_collaborators_carry_the_given_resolver(): void
    {
        // the guarantee the old constructor fallback could silently break: an aliased event judged
        // by a payload-props extractor. Both collaborators must read through the resolver given
        // here, proven on the object, not the class.
        $resolver = new StubEventResolver;
        $engine = $this->builder(resolver: $resolver)->build();

        $executor = $this->grab($engine, Engine::class, 'executor');
        self::assertInstanceOf(StepExecutor::class, $executor);
        $performer = $this->grab($executor, StepExecutor::class, 'performer');
        self::assertInstanceOf(StepPerformer::class, $performer);
        foreach (['joins' => JoinSettler::class, 'families' => FamilyGate::class] as $property => $class) {
            $collaborator = $this->grab($performer, StepPerformer::class, $property);
            self::assertInstanceOf($class, $collaborator);
            $extraction = $this->grab($collaborator, $class, 'extraction');
            self::assertInstanceOf(WaitVarExtractor::class, $extraction);
            self::assertSame($resolver, $this->grab($extraction, WaitVarExtractor::class, 'events'));
        }
    }

    #[Test]
    public function the_optional_capabilities_and_store_overrides_are_kept(): void
    {
        $calendar = new ConfiguredBusinessCalendar;
        $breaker = new CircuitBreaker($this->createStub(CircuitBreakerStorage::class), new MutableClock);
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $timers = $this->createStub(WorkflowTimerStore::class);
        $serializer = new DefaultMessageSerializer;
        $context = ContextValues::empty();

        $engine = $this->builder()
            ->calendar($calendar)
            ->circuitBreaker($breaker)
            ->instances($instances)
            ->timers($timers)
            ->serializer($serializer)
            ->context($context)
            ->build();

        $executor = $this->grab($engine, Engine::class, 'executor');
        self::assertInstanceOf(StepExecutor::class, $executor);
        $committer = $this->grab($executor, StepExecutor::class, 'committer');
        self::assertInstanceOf(StepCommitter::class, $committer);
        self::assertSame($calendar, $this->grab($committer, StepCommitter::class, 'calendar'));
        self::assertSame($instances, $this->grab($committer, StepCommitter::class, 'instances'));
        self::assertSame($timers, $this->grab($committer, StepCommitter::class, 'timers'));

        // the given codec and ambient context are KEPT, never replaced by the bare defaults
        $outbox = $this->grab($committer, StepCommitter::class, 'outbox');
        self::assertInstanceOf(WorkflowOutbox::class, $outbox);
        $writer = $this->grab($outbox, WorkflowOutbox::class, 'writer');
        self::assertInstanceOf(DbalWorkflowOutboxWriter::class, $writer);
        self::assertSame($serializer, $this->grab($writer, DbalWorkflowOutboxWriter::class, 'serializer'));
        $protocol = $this->grab($outbox, WorkflowOutbox::class, 'protocol');
        self::assertInstanceOf(HopProtocol::class, $protocol);
        self::assertSame($context, $this->grab($protocol, HopProtocol::class, 'context'));

        $performer = $this->grab($executor, StepExecutor::class, 'performer');
        self::assertInstanceOf(StepPerformer::class, $performer);
        $machine = $this->grab($performer, StepPerformer::class, 'machine');
        self::assertInstanceOf(MachineRunner::class, $machine);
        $activity = $this->grab($machine, MachineRunner::class, 'activity');
        self::assertInstanceOf(ActivityRunner::class, $activity);
        self::assertSame($breaker, $this->grab($activity, ActivityRunner::class, 'breaker'));
    }

    private function builder(?StubEventResolver $resolver = null): DbalSagaRuntimeBuilder
    {
        return DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry([]))
            ->connection($this->createStub(Connection::class))
            ->eventResolver($resolver ?? new StubEventResolver)
            ->clock(new MutableClock)
            ->events($this->createStub(EventDispatcherInterface::class));
    }

    /**
     * @param  class-string  $class
     */
    private function grab(object $node, string $class, string $property): mixed
    {
        return new ReflectionProperty($class, $property)->getValue($node);
    }
}
