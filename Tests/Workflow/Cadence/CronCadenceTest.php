<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow\Cadence;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Workflow\Cadence\CronCadence;

final class CronCadenceTest extends TestCase
{
    /** A cron grid is absolute, so the anchor argument is inert; any instant does. */
    private const string ANY_ANCHOR = '2026-01-01T00:00:00.000000Z';

    #[Test]
    public function the_next_fire_is_the_next_cron_occurrence(): void
    {
        $cadence = new CronCadence('0 * * * *'); // the top of every hour

        self::assertSame(
            '2026-06-15T11:00:00.000000+00:00',
            $cadence->nextFireAfter(
                PointInTime::from('2026-06-15T10:30:00.000000Z'),
                PointInTime::from(self::ANY_ANCHOR),
            )->toString(),
        );
    }

    #[Test]
    public function the_grid_is_absolute_and_ignores_the_instance_anchor(): void
    {
        // two instances born hours apart still fire on the same calendar slot; the global counterpart
        // to an anchored interval, where the two would diverge by their birth offset
        $cadence = new CronCadence('0 * * * *');
        $now = PointInTime::from('2026-06-15T10:30:00.000000Z');

        self::assertSame(
            $cadence->nextFireAfter($now, PointInTime::from('2026-01-01T00:00:00.000000Z'))->toString(),
            $cadence->nextFireAfter($now, PointInTime::from('2026-06-15T10:17:00.000000Z'))->toString(),
        );
    }

    #[Test]
    public function it_counts_every_occurrence_across_a_gap(): void
    {
        // (10:00, 13:00] for an hourly cron yields 11:00, 12:00, 13:00
        self::assertSame(3, new CronCadence('0 * * * *')->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            PointInTime::from(self::ANY_ANCHOR),
        ));
    }

    #[Test]
    public function no_occurrence_is_due_before_the_next_one(): void
    {
        // the 1st of the month, nothing due mid-month
        self::assertSame(0, new CronCadence('0 0 1 * *')->countDue(
            PointInTime::from('2026-06-15T00:00:00.000000Z'),
            PointInTime::from('2026-06-20T00:00:00.000000Z'),
            PointInTime::from(self::ANY_ANCHOR),
        ));
    }

    #[Test]
    public function the_cron_grid_is_utc_not_market_local(): void
    {
        // `0 2 * * *` means 02:00 UTC, the canonical instant, never a market's local 02:00. A
        // market-local recurrence belongs to a BusinessCalendar, not to a cron offset; pinned so a
        // future "fix" toward local time cannot slip in silently.
        $cadence = new CronCadence('0 2 * * *');

        $next = $cadence->nextFireAfter(PointInTime::from('2026-06-15T05:00:00.000000Z'), PointInTime::from('2026-06-01T00:00:00.000000Z'));

        $this->assertSame('2026-06-16T02:00:00.000000+00:00', $next->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unsatisfiable_expression_fails_the_next_fire_as_what_it_is(): void
    {
        // the runtime twin of the build rule: a per-instance cron, via cronVar or cronMethod, is only known
        // at the tick, so '0 0 30 2 *' can reach a LIVE cadence; the library's raw RuntimeException
        // would roll back the step and DLQ as noise; the cadence owns the failure type instead
        $cadence = new CronCadence('0 0 30 2 *'); // parses, but February 30th never comes

        $this->expectException(InvalidResolvedCron::class);
        $this->expectExceptionMessageMatches('/0 0 30 2/'); // the message NAMES the poison expression

        $cadence->nextFireAfter(PointInTime::from('2026-06-15T10:30:00.000000Z'), PointInTime::from(self::ANY_ANCHOR));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unsatisfiable_expression_fails_the_catch_up_count_too(): void
    {
        // countDue walks the same grid; its very first step must fail as InvalidResolvedCron as well,
        // not leak the library's RuntimeException through the catch-up path
        $this->expectException(InvalidResolvedCron::class);

        new CronCadence('0 0 30 2 *')->countDue(
            PointInTime::from('2026-06-15T10:00:00.000000Z'),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            PointInTime::from(self::ANY_ANCHOR),
        );
    }
}
