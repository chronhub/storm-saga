<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Throwable;

/**
 * The circuit-breaker runtime: the policy logic over a swappable `CircuitBreakerStorage`.
 *
 * `allows()` is the gate the `ActivityRunner` checks before running a protected activity: a `Closed`
 * breaker admits, an `Open` one admits only once its cooldown has elapsed, the half-open probe derived
 * here from `openedAt` and the policy, never persisted. After the activity, the runner reports the
 * outcome via `recordSuccess()` and `recordFailure()`; the storage does the atomic counting and
 * tripping, since a shared breaker is concurrent.
 *
 * Half-open probe admission is racy by design: it is read non-transactionally, so as many workers as are
 * mid-`allows()` in that instant slip through, and the burst scales with concurrency, not literally "a
 * couple". Acceptable, since the first success closes it and a failure re-opens it; a strict
 * single-probe guarantee would need a compare-and-set on a half-open token, which NEITHER shipped
 * storage implements, the Redis adapter included.
 *
 * @see ActivityRunner
 */
final readonly class CircuitBreaker
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private CircuitBreakerStorage $storage,
        private Clock $clock,
    ) {}

    /**
     * May a call through `$policy`'s resource proceed? Closed admits; Open admits only after the cooldown,
     * a half-open probe.
     *
     * @throws Throwable on a storage failure reading the breaker, clock exceptions
     */
    public function allows(CircuitBreakerPolicy $policy): bool
    {
        $snapshot = $this->storage->read($policy->key);

        return match ($snapshot->state) {
            // HalfOpen is derived in state(), never persisted, so this arm is unreachable in allows(); dropping the HalfOpen case is an equivalent mutant
            BreakerState::Closed, BreakerState::HalfOpen => true,
            BreakerState::Open => $this->cooldownElapsed($snapshot, $policy),
        };
    }

    /**
     * @throws Throwable on a storage failure writing the breaker
     */
    public function recordSuccess(CircuitBreakerPolicy $policy): void
    {
        $this->storage->recordSuccess($policy->key);
    }

    /**
     * @throws Throwable on a storage failure writing the breaker
     */
    public function recordFailure(CircuitBreakerPolicy $policy): void
    {
        $this->storage->recordFailure($policy->key, $policy->failureThreshold);
    }

    /**
     * The breaker's reported posture for `$policy`: `Open` collapses to `HalfOpen` once the cooldown
     * has elapsed, for telemetry or health; the persisted state stays Closed/Open.
     *
     * @throws Throwable on a storage failure reading the breaker, clock exceptions
     */
    public function state(CircuitBreakerPolicy $policy): BreakerState
    {
        $snapshot = $this->storage->read($policy->key);

        return $snapshot->state === BreakerState::Open && $this->cooldownElapsed($snapshot, $policy)
            ? BreakerState::HalfOpen
            : $snapshot->state;
    }

    /**
     * @throws ClockExceptionContract when the cooldown instant cannot be derived
     */
    private function cooldownElapsed(BreakerSnapshot $snapshot, CircuitBreakerPolicy $policy): bool
    {
        // the openedAt===null side is defensive: recordFailure always stamps openedAt,
        // so that arm of the `||` is unreachable in normal flow, an equivalent mutant
        if ($snapshot->openedAt === null || $policy->cooldownSeconds < 1) {
            return true; // open without a timestamp or a non-positive cooldown admits a probe, defensively
        }

        return ! $this->clock->now()->isBefore($snapshot->openedAt->addSeconds($policy->cooldownSeconds));
    }
}
