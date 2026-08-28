<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker;

use Storm\Clock\PointInTime;

/**
 * An immutable read of a breaker's persisted state for a key: its `BreakerState` of Closed or Open,
 * the consecutive failures count, and openedAt, set when it tripped or null while closed. The breaker
 * derives the half-open admission from openedAt and the policy's cooldown, so the snapshot stays
 * storage-only: it reads no clock and applies no policy. openedAt is a `PointInTime`, a canonical
 * instant.
 */
final readonly class BreakerSnapshot
{
    public function __construct(
        public BreakerState $state,
        public int $failures,
        public ?PointInTime $openedAt = null,
    ) {}
}
