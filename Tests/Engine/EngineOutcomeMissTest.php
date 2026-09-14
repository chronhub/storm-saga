<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Runtime\Dbal\DbalSagaRuntimeBuilder;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Stringable;

/**
 * The one trace a routed outcome leaves when it reaches no instance. The engine answers false there,
 * and false alone is indistinguishable from an event no saga was ever waiting for, so a routing key
 * that arrived wrong, a correlation dropped on the wire and replaced by a fresh one, costs a saga its
 * advance with nothing to read anywhere. The line names the key and the class, never the payload.
 */
final class EngineOutcomeMissTest extends TestCase
{
    #[Test]
    public function an_outcome_that_matches_no_instance_leaves_its_line(): void
    {
        $logger = new OutcomeLogRecorder;

        $this->assertFalse($this->engine($logger)->routeOutcome('nobody-waits-on-this', new SampleEvent));

        $this->assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        $this->assertSame('debug', $level);
        $this->assertSame('storm.saga.outcome_unrouted', $message);
        $this->assertSame(['correlation_id' => 'nobody-waits-on-this', 'event' => SampleEvent::class], $context);
    }

    #[Test]
    public function an_engine_with_no_logger_stays_silent(): void
    {
        // the standalone shape: no channel given, so the miss costs nothing and throws nothing
        $this->assertFalse($this->engine(null)->routeOutcome('nobody-waits-on-this', new SampleEvent));
    }

    private function engine(?OutcomeLogRecorder $logger): Engine
    {
        // no instance carries the key, which is the whole scenario; nothing else is reached, so the
        // connection is never touched and the DBAL graph around it stays lazy
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('findByCorrelation')->willReturn(null);

        $builder = DbalSagaRuntimeBuilder::withRegistry(new WorkflowRegistry)
            ->connection($this->createStub(Connection::class))
            ->eventResolver(new StubEventResolver)
            ->clock(new MutableClock)
            ->events($this->createStub(EventDispatcherInterface::class))
            ->instances($instances);

        return ($logger === null ? $builder : $builder->logger($logger))->build();
    }
}

final class OutcomeLogRecorder extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, (string) $message, $context];
    }
}
