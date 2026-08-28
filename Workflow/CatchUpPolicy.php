<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;

/**
 * The resolved catch-up policy of a schedule state: the `CatchUp` mode plus its parameters. Given how
 * many grid slots came due, the fired slot included, it decides how many ticks to actually fire, never
 * more than `MAX_TICKS`, a hard ceiling so a long outage can never materialize an unbounded catch-up. The
 * `ReplayAll` window clamps how far back eligibility reaches, Temporal's CatchupWindow.
 */
final readonly class CatchUpPolicy
{
    /** The unbounded-replay backstop: never fire more than this many ticks in one catch-up. */
    public const int MAX_TICKS = 10000;

    public function __construct(
        public CatchUp $mode,
        public ?int $limit = null,
        public ?int $windowSeconds = null,
    ) {}

    /**
     * How many ticks to fire, given `$due` grid slots came due, at least 1 since the fired slot counts.
     * Skip collapses the gap to one; ReplayBounded caps at `limit`; ReplayAll fires them all; every mode is
     * floored to 1 and ceilinged to MAX_TICKS.
     */
    public function ticksFor(int $due): int
    {
        $ticks = match ($this->mode) {
            // @infection-ignore-all; equivalent: the max(1, ...) floor below makes Skip=>1 and Skip=>0 indistinguishable
            CatchUp::Skip => 1,
            // @infection-ignore-all: the build guarantees ReplayBounded carries a limit, so the `?? 1` fallback is unreachable
            CatchUp::ReplayBounded => min($due, $this->limit ?? 1),
            CatchUp::ReplayAll => $due,
        };

        return max(1, min($ticks, self::MAX_TICKS));
    }

    /**
     * The earliest instant a missed slot is still eligible: `ReplayAll` reaches back at most
     * `windowSeconds` from `$now`; every other mode, or a window-less `ReplayAll`, imposes no floor.
     *
     * @throws InvalidDateTimeException when the floor instant cannot be derived
     */
    public function windowFloor(PointInTime $now): ?PointInTime
    {
        if ($this->mode !== CatchUp::ReplayAll || $this->windowSeconds === null) {
            return null;
        }

        // @infection-ignore-all; defensive floor: subSeconds takes int<1,max>, and a window is >= 1s in practice
        return $now->subSeconds(max(1, $this->windowSeconds));
    }
}
