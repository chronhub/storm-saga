<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\CircuitBreaker\BreakerSnapshot;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;

/**
 * In-memory `CircuitBreakerStorage` over the shared state, on the same controlled clock as the rest
 * of the runtime: an absent key reads closed with zero failures, a success resets, and a failure
 * that reaches the threshold opens the breaker stamped at the clock's instant, so a cooldown test
 * advances time instead of sleeping.
 */
final readonly class InMemoryCircuitBreakers implements CircuitBreakerStorage
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private InMemorySagaState $state,
        private Clock $clock,
    ) {}

    public function read(string $key): BreakerSnapshot
    {
        $entry = $this->state->breakers[$key] ?? null;
        if ($entry === null) {
            return new BreakerSnapshot(BreakerState::Closed, 0);
        }

        return new BreakerSnapshot(
            BreakerState::from($entry['state']),
            $entry['failures'],
            $entry['openedAt'] !== null ? PointInTime::fromStorage($entry['openedAt']) : null,
        );
    }

    public function recordSuccess(string $key): void
    {
        if (isset($this->state->breakers[$key])) {
            $this->state->breakers[$key] = ['state' => BreakerState::Closed->value, 'failures' => 0, 'openedAt' => null];
        }
    }

    public function recordFailure(string $key, int $threshold): void
    {
        $entry = $this->state->breakers[$key] ?? ['state' => BreakerState::Closed->value, 'failures' => 0, 'openedAt' => null];
        $failures = $entry['failures'] + 1;
        $opens = $failures >= $threshold;

        $this->state->breakers[$key] = [
            'state' => $opens ? BreakerState::Open->value : $entry['state'],
            'failures' => $failures,
            'openedAt' => $opens ? $this->clock->now()->toString() : $entry['openedAt'],
        ];
    }
}
