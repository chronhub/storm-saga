<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\CircuitBreaker\BreakerSnapshot;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;

/**
 * Process-local `CircuitBreakerStorage`: the counters live in a PHP array and die with the process.
 *
 * The trade is the breaker's whole point, so choose it knowing what it costs. A shared breaker exists to
 * let N workers pool their evidence about one flaky resource; here each process trips on its own count,
 * so a threshold of 3 across 8 workers admits up to 24 failures before every one of them is open, and a
 * restart forgets the trip entirely. What it buys is a breaker with no storage on the path: no row, no
 * round trip, no outage of its own.
 *
 * That fits three shapes and no others: a single-process CLI run, a test that wants the real adapter
 * rather than a stand-in, and a deliberately per-worker breaker guarding a resource whose failures are
 * local to the caller, a saturated connection pool held by that process rather than a downed remote.
 * Anything else wants Postgres or Redis.
 *
 * Atomicity is free and not a claim: PHP shares nothing between requests or workers, so the read and the
 * write cannot interleave with anyone. `openedAt` is stamped from the injected clock, which a test can
 * freeze and advance to walk the cooldown without sleeping.
 */
final class InMemoryCircuitBreakerStorage implements CircuitBreakerStorage
{
    /** @var array<string, BreakerSnapshot> */
    private array $breakers = [];

    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function read(string $key): BreakerSnapshot
    {
        return $this->breakers[$key] ?? new BreakerSnapshot(BreakerState::Closed, 0);
    }

    public function recordSuccess(string $key): void
    {
        $this->breakers[$key] = new BreakerSnapshot(BreakerState::Closed, 0);
    }

    public function recordFailure(string $key, int $threshold): void
    {
        $current = $this->read($key);
        $failures = $current->failures + 1;

        $this->breakers[$key] = $failures >= $threshold
            ? new BreakerSnapshot(BreakerState::Open, $failures, $this->clock->now())
            : new BreakerSnapshot($current->state, $failures, $current->openedAt);
    }
}
