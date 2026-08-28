<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow\Cadence;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\Cadence\SpreadCadence;

final class SpreadCadenceTest extends TestCase
{
    /** Hourly inner grid anchored at 10:00, translated by 10 minutes: shifted slots 11:10, 12:10, … */
    private const string ANCHOR = '2026-06-15T10:00:00.000000Z';

    #[Test]
    public function the_next_fire_is_the_inner_slot_translated_by_the_offset(): void
    {
        // inner grid 11:00, 12:00, … gives shifted 11:10; now 10:30 sits before the first shifted slot
        self::assertSame(
            '2026-06-15T11:10:00.000000+00:00',
            $this->hourlyShiftedBy(600)->nextFireAfter(
                PointInTime::from('2026-06-15T10:30:00.000000Z'),
                PointInTime::from(self::ANCHOR),
            )->toString(),
        );
    }

    #[Test]
    public function the_next_fire_is_strictly_after_a_shifted_slot_landed_on(): void
    {
        // now sits exactly on a SHIFTED grid point, so it yields the next one, never itself; the inner
        // strictly-after contract survives the translation
        self::assertSame(
            '2026-06-15T12:10:00.000000+00:00',
            $this->hourlyShiftedBy(600)->nextFireAfter(
                PointInTime::from('2026-06-15T11:10:00.000000Z'),
                PointInTime::from(self::ANCHOR),
            )->toString(),
        );
    }

    #[Test]
    public function the_first_fire_never_precedes_the_birth(): void
    {
        // now == birth: the shifted now falls BEFORE the anchor, the inner clamp holds, and the first
        // fire is birth + period + offset, never a pre-birth instant
        self::assertSame(
            '2026-06-15T11:10:00.000000+00:00',
            $this->hourlyShiftedBy(600)->nextFireAfter(
                PointInTime::from(self::ANCHOR),
                PointInTime::from(self::ANCHOR),
            )->toString(),
        );
    }

    #[Test]
    public function count_due_sees_the_shifted_slots_in_the_window(): void
    {
        // shifted slots 12:10 and 13:10 fall in (11:10, 13:10]; the window translates back onto the
        // inner grid, half-open edges preserved, the fired slot 11:10 itself excluded
        self::assertSame(2, $this->hourlyShiftedBy(600)->countDue(
            PointInTime::from('2026-06-15T11:10:00.000000Z'),
            PointInTime::from('2026-06-15T13:10:00.000000Z'),
            PointInTime::from(self::ANCHOR),
        ));
    }

    #[Test]
    public function no_shifted_slot_falls_inside_one_period(): void
    {
        // (10:10, 11:00] holds no shifted slot, 11:10 is just outside; an unshifted grid would have
        // counted 11:00. The translation moves the boundary, the count stays honest.
        self::assertSame(0, $this->hourlyShiftedBy(600)->countDue(
            PointInTime::from('2026-06-15T10:10:00.000000Z'),
            PointInTime::from('2026-06-15T11:00:00.000000Z'),
            PointInTime::from(self::ANCHOR),
        ));
    }

    #[Test]
    public function a_calendar_grid_spreads_within_its_slot(): void
    {
        // "the 1st of the month at midnight" + a one-hour offset gives the 1st at 01:00. The spread
        // shifts the calendar instant itself, the conscious business trade a calendar spread makes.
        self::assertSame(
            '2026-07-01T01:00:00.000000+00:00',
            new SpreadCadence(new CronCadence('0 0 1 * *'), 3600)->nextFireAfter(
                PointInTime::from('2026-06-15T00:00:00.000000Z'),
                PointInTime::from(self::ANCHOR), // inert, a calendar grid ignores the anchor
            )->toString(),
        );
    }

    #[Test]
    #[Group('adversarial')]
    public function an_offset_larger_than_the_period_stays_a_coherent_grid(): void
    {
        // spread > period is pointless, the translation equivalent modulo the period, but it must stay harmless:
        // the grid keeps its period, just phased, and the build cannot bound it for a per-instance
        // cron, so the math has to tolerate it
        $cadence = $this->hourlyShiftedBy(5400); // 1.5 periods

        $first = $cadence->nextFireAfter(PointInTime::from('2026-06-15T10:30:00.000000Z'), PointInTime::from(self::ANCHOR));
        $second = $cadence->nextFireAfter($first, PointInTime::from(self::ANCHOR));

        self::assertSame('2026-06-15T12:30:00.000000+00:00', $first->toString());
        self::assertSame('2026-06-15T13:30:00.000000+00:00', $second->toString()); // one period apart
    }

    /**
     * @param  int<1, max>  $offsetSeconds
     */
    private function hourlyShiftedBy(int $offsetSeconds): SpreadCadence
    {
        return new SpreadCadence(new IntervalCadence(3600), $offsetSeconds);
    }
}
