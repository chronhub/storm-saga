<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;

/**
 * A test clock whose `now` is a public, settable instant, so a test can advance time, for example to
 * step a circuit breaker past its cooldown into the half-open probe.
 *
 * @implements Clock<PointInTime>
 */
final class MutableClock implements Clock
{
    public PointInTime $now;

    public function __construct()
    {
        $this->now = new PointInTime;
    }

    public function now(): PointInTime
    {
        return $this->now;
    }
}
