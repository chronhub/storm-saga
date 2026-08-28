<?php

declare(strict_types=1);

namespace Storm\Saga\Calendar;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;

/**
 * Resolves a business-time deadline to an absolute instant: the relative-to-absolute conversion a saga's
 * timeout needs when its deadline is declared in BUSINESS time, such as "T+2 business days" for
 * settlement or "4 business hours" for an SLA, rather than wall-clock seconds. Weekends, holidays and
 * out-of-hours periods are skipped. A days-only advance lands on a business DAY keeping the time-of-day,
 * so T+2 business days from Friday 20:00 is Tuesday 20:00, possibly outside business hours since the day
 * is business but the instant need not be; only an hours component pins the result inside a business
 * window.
 *
 * A PORT, deliberately unbound by default. The app opts in through `storm.saga.calendar`, which registers
 * `ConfiguredBusinessCalendar` with the DECLARED market, or binds its own market-accurate calendar with
 * its holiday set, hours and timezone; a per-market named calendar is a deferred extension. Unbound, a
 * business-time arm fails loud with `BusinessCalendarMissing`, since an implicit fictitious market would
 * compute silently-wrong deadlines.
 */
interface BusinessCalendar
{
    /**
     * Advance `$from` by a business duration: `$businessDays` whole business days, calendar days that are
     * business with weekends and holidays skipped, keeping the time-of-day, THEN `$businessHours` hours of
     * elapsed business time, consumed only within business hours and crossing closes and weekends. Returns
     * the absolute UTC instant the deadline resolves to.
     *
     * @throws ClockExceptionContract when the instant cannot be derived
     */
    public function advance(PointInTime $from, int $businessDays, int $businessHours): PointInTime;

    /**
     * Whether `$at` falls within a business period: a business day, not a weekend or holiday, AND within
     * business hours.
     */
    public function isBusinessTime(PointInTime $at): bool;
}
