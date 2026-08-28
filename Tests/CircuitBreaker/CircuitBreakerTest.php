<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\CircuitBreaker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Workflow\CircuitBreakerPolicy;

final class CircuitBreakerTest extends TestCase
{
    #[Test]
    public function opens_after_the_threshold_then_half_opens_after_the_cooldown_then_closes(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 2, cooldownSeconds: 60);

        $this->assertTrue($breaker->allows($policy));                       // closed, admits
        $this->assertSame(BreakerState::Closed, $breaker->state($policy));

        $breaker->recordFailure($policy);
        $this->assertTrue($breaker->allows($policy));                       // 1 failure < threshold, still closed

        $breaker->recordFailure($policy);                                   // reaches threshold, opens
        $this->assertFalse($breaker->allows($policy));                      // fast-fail: cooldown not elapsed
        $this->assertSame(BreakerState::Open, $breaker->state($policy));

        $clock->now = $clock->now->addSeconds(61);                          // cooldown elapses
        $this->assertTrue($breaker->allows($policy));                       // half-open probe admitted
        $this->assertSame(BreakerState::HalfOpen, $breaker->state($policy));

        $breaker->recordSuccess($policy);                                   // probe succeeds, closes
        $this->assertTrue($breaker->allows($policy));
        $this->assertSame(BreakerState::Closed, $breaker->state($policy));
    }

    #[Test]
    public function a_failure_in_half_open_re_opens_and_restarts_the_cooldown(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('svc', failureThreshold: 1, cooldownSeconds: 30);

        $breaker->recordFailure($policy);                                   // threshold 1, opens immediately
        $this->assertFalse($breaker->allows($policy));

        $clock->now = $clock->now->addSeconds(31);                          // cooldown, then half-open
        $this->assertTrue($breaker->allows($policy));

        $breaker->recordFailure($policy);                                   // probe fails, re-opens; opened_at refreshed
        $this->assertFalse($breaker->allows($policy));                      // open again, cooldown restarted from now
    }

    #[Test]
    public function a_one_second_cooldown_is_a_real_cooldown_not_a_defensive_admit(): void
    {
        // the defensive "admit immediately" guard is `cooldownSeconds < 1`, so 1 is a real cooldown,
        // not yet elapsed at the moment the breaker opens: the boundary is `< 1`, not `<= 1`
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('svc', failureThreshold: 1, cooldownSeconds: 1);

        $breaker->recordFailure($policy);                                   // opens, openedAt = now

        $this->assertFalse($breaker->allows($policy));                      // 1s cooldown not elapsed, fast-fail
    }
}
