<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow\Cadence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Workflow\Cadence\IntervalCadence;

final class IntervalCadenceTest extends TestCase
{
    #[Test]
    public function the_next_fire_is_one_interval_after_the_anchor(): void
    {
        // born at :17 past the hour, so the grid runs :17, not :00; this is the per-instance phase
        $cadence = new IntervalCadence(3600);

        self::assertSame(
            '2026-06-15T11:17:00.000000+00:00',
            $cadence->nextFireAfter(
                PointInTime::from('2026-06-15T10:30:00.000000Z'),  // now
                PointInTime::from('2026-06-15T10:17:00.000000Z'),  // anchor, startedAt
            )->toString(),
        );
    }

    #[Test]
    public function the_next_fire_is_strictly_after_a_slot_landed_on(): void
    {
        $cadence = new IntervalCadence(3600);

        // now sits exactly on a grid point, anchor + 1·interval, so it yields the NEXT one, never itself
        self::assertSame(
            '2026-06-15T12:00:00.000000+00:00',
            $cadence->nextFireAfter(
                PointInTime::from('2026-06-15T11:00:00.000000Z'),  // now == anchor + 3600
                PointInTime::from('2026-06-15T10:00:00.000000Z'),  // anchor
            )->toString(),
        );
    }

    #[Test]
    public function no_slots_are_due_inside_one_interval(): void
    {
        self::assertSame(0, new IntervalCadence(3600)->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),
            PointInTime::from('2026-06-15T10:30:00.000000Z'),
            PointInTime::from('2026-06-15T10:00:00.000000Z'),  // anchor
        ));
    }

    #[Test]
    public function one_slot_is_due_at_the_interval_boundary(): void
    {
        self::assertSame(1, new IntervalCadence(3600)->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),
            PointInTime::from('2026-06-15T11:00:00.000000Z'),
            PointInTime::from('2026-06-15T10:00:00.000000Z'),  // anchor, slot lands at 11:00
        ));
    }

    #[Test]
    public function it_counts_every_missed_slot_across_a_gap(): void
    {
        // (10:00, 13:00] over an hourly grid anchored at 10:00 gives 11:00, 12:00, 13:00
        self::assertSame(3, new IntervalCadence(3600)->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            PointInTime::from('2026-06-15T10:00:00.000000Z'),  // anchor
        ));
    }

    #[Test]
    public function a_window_reaching_before_birth_counts_only_the_real_slots(): void
    {
        // the window floor, 10:00, is two full periods before the anchor, 12:00; no slot exists before
        // anchor + one period, so the count clamps to the real grid: just 13:00 in (10:00, 13:00], giving 1,
        // never the phantom 11:00/12:00 a raw negative offset would conjure
        self::assertSame(1, new IntervalCadence(3600)->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),  // after, before birth
            PointInTime::from('2026-06-15T13:00:00.000000Z'),  // through
            PointInTime::from('2026-06-15T12:00:00.000000Z'),  // anchor, born at 12:00
        ));
    }

    #[Test]
    public function the_anchor_phase_shifts_which_slots_fall_in_a_window(): void
    {
        // same window (10:00, 11:00], same period, but the phase decides the boundary. Anchored at
        // 10:00 the slot 11:00 is included, giving 1; anchored at 10:30 the grid runs :30, so the next
        // slot is 11:30, outside the window, giving 0. The phase, not the gap, moves the slot.
        $cadence = new IntervalCadence(3600);
        $after = PointInTime::from('2026-06-15T10:00:00.000000Z');
        $through = PointInTime::from('2026-06-15T11:00:00.000000Z');

        self::assertSame(1, $cadence->countDue($after, $through, PointInTime::from('2026-06-15T10:00:00.000000Z')));
        self::assertSame(0, $cadence->countDue($after, $through, PointInTime::from('2026-06-15T10:30:00.000000Z')));
    }
}
