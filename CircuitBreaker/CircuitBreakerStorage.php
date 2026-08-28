<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker;

use Throwable;

/**
 * Swappable persistence for circuit-breaker state, keyed by a dev-chosen resource key such as
 * `payment-gateway`, so every caller of that resource shares one breaker across saga instances and
 * workers. The adapter decides the backend:
 *
 * - Postgres, the default and on-brand.
 * - Redis, opt-in for high frequency.
 * - In-memory, process-local, for a single process or tests.
 *
 * Which one is a config choice, `storm.saga.circuit_breaker.storage`, and it decides HOW FAR the sharing
 * reaches: across every worker and across restarts, across every worker only, or not at all. A test
 * kit's own storage plugs in here too, which is how a controlled clock walks a cooldown without sleeping.
 *
 * The interface is intentionally small and the writes are atomic, since a shared breaker is concurrent
 * by nature: `recordFailure` increments and trips at the threshold in one operation, `recordSuccess`
 * resets. The half-open admission cooldown and the policy thresholds live in the breaker, not here; this
 * is just the durable counter.
 */
interface CircuitBreakerStorage
{
    /**
     * The current persisted snapshot for the given key, a fresh Closed/0/null when the key is
     * unknown.
     *
     * @throws Throwable on a storage failure
     */
    public function read(string $key): BreakerSnapshot;

    /**
     * Record a success: reset the failure count and close the breaker. Atomic.
     *
     * @throws Throwable on a storage failure
     */
    public function recordSuccess(string $key): void;

    /**
     * Record a failure: increment the consecutive-failure count and, once it reaches the
     * threshold, trip the breaker open, stamping the open instant, which restarts the cooldown.
     * Atomic.
     *
     * @throws Throwable on a storage failure
     */
    public function recordFailure(string $key, int $threshold): void;
}
