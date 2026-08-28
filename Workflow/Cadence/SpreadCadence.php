<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Cadence;

use Storm\Clock\PointInTime;

/**
 * Translates an inner cadence's whole grid by a fixed per-instance offset: every slot fires
 * `$offsetSeconds` later than the inner grid says. This is what de-synchronizes a fleet born in a burst,
 * such as a mass migration or an import: the per-instance interval grid is phased on the birth, so batched
 * births mean coinciding slots forever, and the translation spreads them without touching the period, the
 * anchor as a factual birth instant, or the per-instance model.
 *
 * Pure math over the inner grid: shifted slots equal inner slots plus the offset, so a window query
 * translates back, a shifted slot falling in (after, through] exactly when the inner slot falls in
 * (after-offset, through-offset], and the next fire is the inner next fire of the shifted now, shifted
 * forward. It works over any inner cadence: an interval grid shifts within its period; a calendar grid
 * fires on the 1st of the month plus the instance's offset. It never fires in the past: the result is
 * strictly after `$now`, which is never before the instance's birth, so the never-before-birth guarantee
 * holds untouched.
 *
 * `$offsetSeconds` is at least 1 by contract; a zero offset is the inner cadence and the caller skips the
 * decoration, since `SpreadOffset` can yield 0.
 *
 * @see SpreadOffset the deterministic per-instance offset derivation
 */
final readonly class SpreadCadence implements Cadence
{
    /**
     * @param  int<1, max>  $offsetSeconds
     */
    public function __construct(
        public Cadence $inner,
        public int $offsetSeconds,
    ) {}

    public function nextFireAfter(PointInTime $now, PointInTime $anchor): PointInTime
    {
        return $this->inner->nextFireAfter($now->subSeconds($this->offsetSeconds), $anchor)->addSeconds($this->offsetSeconds);
    }

    public function countDue(PointInTime $after, PointInTime $through, PointInTime $anchor): int
    {
        return $this->inner->countDue($after->subSeconds($this->offsetSeconds), $through->subSeconds($this->offsetSeconds), $anchor);
    }
}
