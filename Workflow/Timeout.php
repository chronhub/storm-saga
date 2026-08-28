<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Calendar\BusinessCalendar;

/**
 * A per-state timeout: when its timer fires, the state yields the `OnTrigger::Timeout` trigger, whose
 * target is a `#[On(trigger: 'timeout')]` transition. It is either wall-clock, `$seconds` from now, or
 * business-time via `$businessDays` and `$businessHours`, resolved through a business calendar that skips
 * weekends, holidays, and out-of-hours. `isBusiness()` tells the arm which conversion to apply; for a
 * business timeout `$seconds` is 0 and unused.
 *
 * @see BusinessCalendar
 * @see OnTrigger::Timeout
 */
final readonly class Timeout
{
    public function __construct(
        public int $seconds,
        public ?int $businessDays = null,
        public ?int $businessHours = null,
    ) {}

    /**
     * Whether the deadline is business-time, resolved via a `BusinessCalendar` rather than wall-clock
     * seconds.
     */
    public function isBusiness(): bool
    {
        return $this->businessDays !== null || $this->businessHours !== null;
    }
}
