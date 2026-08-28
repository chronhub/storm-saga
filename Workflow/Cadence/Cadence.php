<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Cadence;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;

/**
 * The recurrence grid of a schedule state, the rhythm a `ScheduleState` ticks on. The engine asks it two
 * things: where the next slot is, to re-arm the timer, and how many slots fell in a past window, the
 * missed-tick count after a late fire. Pure arithmetic over the clock, with no IO and no state.
 *
 * The `$anchor` is the instance's birth instant, its `startedAt`: a per-instance grid is phased on it, so
 * two sagas born a minute apart tick a minute apart, each on its own anniversary. A calendar grid is
 * absolute and ignores it, every instance firing together on the global schedule. That split, interval per
 * instance and cron global, is the whole reason a `ScheduleState` beats a flat scan. Either grid can
 * additionally be spread: a deterministic per-instance translation that de-synchronizes a fleet born in a
 * burst.
 *
 * @see IntervalCadence
 * @see CronCadence
 * @see SpreadCadence
 */
interface Cadence
{
    /**
     * The first grid slot strictly after `$now`, the instant the schedule re-arms its timer to.
     *
     * @param  PointInTime  $anchor  the instance's birth instant; phases a per-instance grid and is ignored by a calendar one
     *
     * @throws ClockExceptionContract when the instant cannot be derived
     */
    public function nextFireAfter(PointInTime $now, PointInTime $anchor): PointInTime;

    /**
     * How many grid slots fall in the half-open range (`$after`, `$through`], the slots that would come due
     * since `$after`, the fired slot itself excluded since the caller counts that one.
     *
     * @param  PointInTime  $anchor  the instance's birth instant; phases a per-instance grid and is ignored by a calendar one
     *
     * @throws ClockExceptionContract when a slot instant cannot be derived
     */
    public function countDue(PointInTime $after, PointInTime $through, PointInTime $anchor): int;
}
