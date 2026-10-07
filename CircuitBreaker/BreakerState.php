<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker;

/**
 * A circuit breaker's state. Persisted values are Closed and Open; HalfOpen is computed by the
 * breaker, an Open breaker whose cooldown has elapsed and admits a probe, never stored. Used by a
 * breaker to report its current posture for telemetry or health.
 */
enum BreakerState: string
{
    /** Normal; calls flow through. */
    case Closed = 'closed';

    /** Tripped; calls fast-fail without touching the protected resource until the cooldown elapses. */
    case Open = 'open';

    /** Cooldown elapsed; probes pass racily, not one at a time, and their outcome closes or re-opens the breaker. */
    case HalfOpen = 'half_open';
}
