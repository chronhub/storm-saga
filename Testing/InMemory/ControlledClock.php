<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;

/**
 * The runtime's owned clock: a fixed instant that moves only when told, never by wall time, so
 * every timer, lease, TTL and stamp in a scenario is deterministic. Advancing time does not run
 * anything; due timers wait for an explicit tick, which is how a test observes "due but not
 * processed" and models worker downtime.
 *
 * @implements Clock<PointInTime>
 */
final class ControlledClock implements Clock
{
    public function __construct(
        private PointInTime $now,
    ) {}

    public function now(): PointInTime
    {
        return $this->now;
    }

    /**
     * @param  positive-int  $seconds
     */
    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->addSeconds($seconds);
    }

    public function set(PointInTime $now): void
    {
        $this->now = $now;
    }
}
