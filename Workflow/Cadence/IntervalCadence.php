<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Cadence;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;

/**
 * A fixed-interval cadence: a slot every `$seconds`, phased on the instance's `$anchor`, its birth. The
 * grid runs `anchor + n*seconds` for n >= 1, never the birth itself, so the first fire is one full period
 * after opening, not at opening. Phasing on the anchor is what makes this per-instance: two sagas born a
 * minute apart tick a minute apart. Pure integer arithmetic on the second; sub-second precision is
 * irrelevant to a schedule, and the timer sweeps floor to 1s anyway. `$seconds` is positive, the build
 * rejects a non-positive interval before constructing this.
 */
final readonly class IntervalCadence implements Cadence
{
    public function __construct(
        public int $seconds,
    ) {}

    public function nextFireAfter(PointInTime $now, PointInTime $anchor): PointInTime
    {
        $anchorTs = $anchor->getTimestamp();
        // slots are anchorTs + j*seconds for j >= 1; the first strictly after now. A saga never steps
        // before its own birth, so the clamp only guards the impossible now < anchor.
        $nowTs = max($anchorTs, $now->getTimestamp());
        $j = intdiv($nowTs - $anchorTs, $this->seconds) + 1;

        return $this->at($anchorTs + $j * $this->seconds);
    }

    public function countDue(PointInTime $after, PointInTime $through, PointInTime $anchor): int
    {
        $anchorTs = $anchor->getTimestamp();
        // a window floor reaching before the saga's birth clamps up to the anchor; no slot exists
        // before anchor + one period, so the count starts there.
        $afterTs = max($anchorTs, $after->getTimestamp());
        $firstTs = $anchorTs + (intdiv($afterTs - $anchorTs, $this->seconds) + 1) * $this->seconds;
        $throughTs = $through->getTimestamp();

        if ($firstTs > $throughTs) {
            return 0;
        }

        return intdiv($throughTs - $firstTs, $this->seconds) + 1;
    }

    /**
     * @throws ClockExceptionContract
     */
    private function at(int $epochSeconds): PointInTime
    {
        return PointInTime::createFromTimestamp($epochSeconds);
    }
}
