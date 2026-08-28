<?php

declare(strict_types=1);

namespace Storm\Saga\Schedule;

/**
 * What one timer pass did: how many due timers it drove, and whether the batch cap cut it short.
 *
 * The second fact is the one a drain needs. A pass reporting a hundred timers reads exactly like a
 * pass that emptied the queue, so an operator following the deployment runbook swaps the code with
 * four hundred still due, carrying yesterday's serialized state. And running timers CREATES relay
 * work, a saga advancing, arming, writing commands, so zero is the only honest end-of-drain signal
 * and it was the one signal the pass could not distinguish.
 */
final readonly class TimerTick
{
    public function __construct(
        public int $processed,
        public bool $moreDue,
    ) {}
}
