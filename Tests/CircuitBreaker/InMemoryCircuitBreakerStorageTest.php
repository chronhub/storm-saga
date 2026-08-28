<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\CircuitBreaker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\Tests\Fixture\MutableClock;

/**
 * The process-local counter, held to the same contract as the Postgres and Redis adapters: one
 * behaviour per test, so a break points at the operation rather than at a scenario. What only THIS
 * adapter can be asked is the isolation its whole trade rests on, that two instances share nothing.
 */
final class InMemoryCircuitBreakerStorageTest extends TestCase
{
    private const string KEY = 'payment-gateway';

    #[Test]
    public function an_unknown_key_reads_a_fresh_closed_snapshot(): void
    {
        $snapshot = new InMemoryCircuitBreakerStorage(new MutableClock)->read(self::KEY);

        self::assertSame(BreakerState::Closed, $snapshot->state);
        self::assertSame(0, $snapshot->failures);
        self::assertNull($snapshot->openedAt);
    }

    #[Test]
    public function failures_below_the_threshold_increment_but_stay_closed(): void
    {
        $storage = new InMemoryCircuitBreakerStorage(new MutableClock);

        $storage->recordFailure(self::KEY, 3);
        $storage->recordFailure(self::KEY, 3);

        $snapshot = $storage->read(self::KEY);
        self::assertSame(BreakerState::Closed, $snapshot->state);
        self::assertSame(2, $snapshot->failures);
        self::assertNull($snapshot->openedAt);
    }

    #[Test]
    public function reaching_the_threshold_trips_the_breaker_open_at_the_clock_instant(): void
    {
        $clock = new MutableClock;
        $storage = new InMemoryCircuitBreakerStorage($clock);

        $storage->recordFailure(self::KEY, 2);
        $storage->recordFailure(self::KEY, 2);

        $snapshot = $storage->read(self::KEY);
        self::assertSame(BreakerState::Open, $snapshot->state);
        self::assertSame(2, $snapshot->failures);
        self::assertNotNull($snapshot->openedAt);
        self::assertTrue($snapshot->openedAt->equals($clock->now));
    }

    #[Test]
    public function a_re_open_restamps_the_open_instant(): void
    {
        $clock = new MutableClock;
        $storage = new InMemoryCircuitBreakerStorage($clock);
        $storage->recordFailure(self::KEY, 1);
        $first = $storage->read(self::KEY)->openedAt;

        $clock->now = $clock->now->addSeconds(30);
        $storage->recordFailure(self::KEY, 1);

        // the cooldown restarts from the LATEST trip, or a breaker re-opening on a failed probe would
        // admit the next one against the instant it first tripped
        self::assertNotNull($first);
        self::assertTrue($storage->read(self::KEY)->openedAt?->isAfter($first));
    }

    #[Test]
    public function a_success_resets_an_open_breaker_to_closed(): void
    {
        $storage = new InMemoryCircuitBreakerStorage(new MutableClock);
        $storage->recordFailure(self::KEY, 1);

        $storage->recordSuccess(self::KEY);

        $snapshot = $storage->read(self::KEY);
        self::assertSame(BreakerState::Closed, $snapshot->state);
        self::assertSame(0, $snapshot->failures);
        self::assertNull($snapshot->openedAt);
    }

    #[Test]
    public function keys_count_independently(): void
    {
        $storage = new InMemoryCircuitBreakerStorage(new MutableClock);

        $storage->recordFailure(self::KEY, 1);

        self::assertSame(BreakerState::Open, $storage->read(self::KEY)->state);
        self::assertSame(BreakerState::Closed, $storage->read('card-network')->state);
    }

    #[Test]
    public function two_instances_share_nothing(): void
    {
        // the adapter's defining trade, asserted rather than assumed: this is what makes a threshold of
        // N across W workers admit up to N*W failures, and what rules the adapter out of a shared remote
        $clock = new MutableClock;
        $first = new InMemoryCircuitBreakerStorage($clock);
        $second = new InMemoryCircuitBreakerStorage($clock);

        $first->recordFailure(self::KEY, 1);

        self::assertSame(BreakerState::Open, $first->read(self::KEY)->state);
        self::assertSame(BreakerState::Closed, $second->read(self::KEY)->state);
    }
}
