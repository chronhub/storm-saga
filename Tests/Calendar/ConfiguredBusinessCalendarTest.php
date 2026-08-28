<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Calendar;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Calendar\ConfiguredBusinessCalendar;
use Storm\Saga\Exception\InvalidBusinessCalendar;

/**
 * The default business calendar's arithmetic, the weight of the business-hours cap. Dates: 2026-06-12
 * is a Friday, 06-13/14 the weekend, 06-15 a Monday. Default config: Mon to Fri, 09:00 to 17:00, UTC,
 * no holidays.
 */
final class ConfiguredBusinessCalendarTest extends TestCase
{
    #[Test]
    public function a_weekday_within_hours_is_business_time(): void
    {
        $this->assertTrue($this->calendar()->isBusinessTime(PointInTime::from('2026-06-15T10:00:00.000000Z')));
    }

    #[Test]
    public function weekends_and_out_of_hours_are_not_business_time(): void
    {
        $cal = $this->calendar();

        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-13T10:00:00.000000Z'))); // Saturday
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T08:00:00.000000Z'))); // before open
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T17:00:00.000000Z'))); // at close, exclusive
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T19:00:00.000000Z'))); // after close
    }

    #[Test]
    public function a_holiday_is_not_business_time(): void
    {
        $cal = new ConfiguredBusinessCalendar(holidays: ['2026-06-15']);

        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T10:00:00.000000Z')));
    }

    #[Test]
    public function advancing_business_days_skips_the_weekend(): void
    {
        // Friday + 2 business days = Tuesday, Sat/Sun skipped, time-of-day kept
        $this->assertSame(
            '2026-06-16T14:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-12T14:00:00.000000Z'), 2, 0)->toString(),
        );
    }

    #[Test]
    public function advancing_business_days_skips_a_holiday(): void
    {
        // Friday + 2 business days, but Tuesday 06-16 is a holiday, so Wednesday 06-17
        $cal = new ConfiguredBusinessCalendar(holidays: ['2026-06-16']);

        $this->assertSame(
            '2026-06-17T14:00:00.000000+00:00',
            $cal->advance(PointInTime::from('2026-06-12T14:00:00.000000Z'), 2, 0)->toString(),
        );
    }

    #[Test]
    public function advancing_business_hours_within_a_day(): void
    {
        $this->assertSame(
            '2026-06-15T15:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-15T14:00:00.000000Z'), 0, 1)->toString(),
        );
    }

    #[Test]
    public function advancing_business_hours_crosses_the_close_to_the_next_day(): void
    {
        // Monday 16:00 + 2 business hours: 1h to close at 17:00, 1h remains, so Tuesday open at 09:00 + 1h = 10:00
        $this->assertSame(
            '2026-06-16T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-15T16:00:00.000000Z'), 0, 2)->toString(),
        );
    }

    #[Test]
    public function advancing_business_hours_crosses_the_weekend(): void
    {
        // Friday 16:00 + 2 business hours: 1h to close, 1h remains, so Monday open at 09:00 + 1h = 10:00
        $this->assertSame(
            '2026-06-15T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-12T16:00:00.000000Z'), 0, 2)->toString(),
        );
    }

    #[Test]
    public function advancing_business_hours_from_outside_a_window_rolls_to_the_next_open(): void
    {
        // Saturday 10:00 + 1 business hour yields Monday open 09:00 + 1h = 10:00
        $this->assertSame(
            '2026-06-15T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-13T10:00:00.000000Z'), 0, 1)->toString(),
        );
    }

    #[Test]
    public function every_default_weekday_is_a_business_day_and_the_full_weekend_is_not(): void
    {
        // pins each entry of the default businessDays [1..5]: Mon 06-15 … Fri 06-19, then Sat/Sun off
        $cal = $this->calendar();

        foreach (['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18', '2026-06-19'] as $weekday) {
            $this->assertTrue($cal->isBusinessTime(PointInTime::from($weekday.'T10:00:00.000000Z')), $weekday);
        }
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-13T10:00:00.000000Z'))); // Saturday
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-14T10:00:00.000000Z'))); // Sunday
    }

    #[Test]
    public function the_open_hour_is_inclusive(): void
    {
        // the exact open hour IS business: the `>=` boundary; a `>` would exclude 09:00
        $this->assertTrue($this->calendar()->isBusinessTime(PointInTime::from('2026-06-15T09:00:00.000000Z')));
    }

    #[Test]
    public function the_last_hour_before_close_is_still_business(): void
    {
        // 16:00 is within: the `< closeHour` boundary holds the door open up to, but not at, 17:00
        $this->assertTrue($this->calendar()->isBusinessTime(PointInTime::from('2026-06-15T16:00:00.000000Z')));
    }

    #[Test]
    public function zero_business_hours_keeps_the_instant_even_outside_a_window(): void
    {
        // seconds <= 0 returns the cursor as-is, time-of-day kept; it must NOT roll into a business window
        $this->assertSame(
            '2026-06-12T20:00:00.000000+00:00', // Friday 20:00 + 0 days + 0 hours stays unchanged, NOT Monday 09:00
            $this->calendar()->advance(PointInTime::from('2026-06-12T20:00:00.000000Z'), 0, 0)->toString(),
        );
    }

    #[Test]
    public function business_hours_that_exactly_reach_close_land_at_close(): void
    {
        // seconds == secondsToClose: lands exactly at 17:00, NOT rolled to the next day's open, the `<=` boundary
        $this->assertSame(
            '2026-06-15T17:00:00.000000+00:00', // Monday 16:00 + 1h = exactly close
            $this->calendar()->advance(PointInTime::from('2026-06-15T16:00:00.000000Z'), 0, 1)->toString(),
        );
    }

    #[Test]
    public function business_hours_spanning_a_close_subtract_only_the_time_to_close(): void
    {
        // Monday 15:00 + 3h: 2h to close, 1h carries to Tuesday 09:00 + 1h = 10:00; pins `$seconds -= $secondsToClose`,
        // chosen so 15:00 makes secondsToClose differ from the carried remainder; a plain `=` would land at 11:00
        $this->assertSame(
            '2026-06-16T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-15T15:00:00.000000Z'), 0, 3)->toString(),
        );
    }

    #[Test]
    public function business_hours_from_before_open_on_a_business_day_roll_forward_to_open(): void
    {
        // Monday 07:00 + 1h: rolls to open 09:00 first, then 10:00; pins rollToBusinessWindow's `hour < openHour`
        $this->assertSame(
            '2026-06-15T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-15T07:00:00.000000Z'), 0, 1)->toString(),
        );
    }

    #[Test]
    public function business_hours_from_after_close_on_a_business_day_roll_to_the_next_open(): void
    {
        // Monday 17:00 at close + 1h: the day's window is spent, so next open Tuesday 09:00 + 1h = 10:00
        // pins rollToBusinessWindow's `hour < closeHour`: a `<=` would treat 17:00 as inside, then 18:00, past close
        $this->assertSame(
            '2026-06-16T10:00:00.000000+00:00',
            $this->calendar()->advance(PointInTime::from('2026-06-15T17:00:00.000000Z'), 0, 1)->toString(),
        );
    }
    // construction: the market shape fails at boot, never inside the step's transaction

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_empty_business_day_set(): void
    {
        // no day is ever open: the business-time walk, `while (!isBusinessDay)`, could never terminate,
        // and it runs at the ARM, inside the step's transaction, fence held
        $this->expectException(InvalidBusinessCalendar::class);
        $this->expectExceptionMessageMatches('/businessDays is empty/');

        new ConfiguredBusinessCalendar(businessDays: []);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_day_outside_iso_range(): void
    {
        // 0 for Sunday is the classic US-style slip; ISO says 7
        $this->expectException(InvalidBusinessCalendar::class);
        $this->expectExceptionMessageMatches('/1 \(Mon\) to 7 \(Sun\)/');

        new ConfiguredBusinessCalendar(businessDays: [0, 1, 2, 3, 4]);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_inverted_hours_window(): void
    {
        // open past close: secondsToClose goes negative and the hours walk diverges
        $this->expectException(InvalidBusinessCalendar::class);
        $this->expectExceptionMessageMatches('/0 <= open < close <= 24/');

        new ConfiguredBusinessCalendar(openHour: 17, closeHour: 9);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_zero_width_hours_window(): void
    {
        $this->expectException(InvalidBusinessCalendar::class);

        new ConfiguredBusinessCalendar(openHour: 9, closeHour: 9);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_non_zero_padded_holiday(): void
    {
        // '2026-1-1' would never match format('Y-m-d'); the deadline would land on the very day it
        // was told to skip, silently
        $this->expectException(InvalidBusinessCalendar::class);
        $this->expectExceptionMessageMatches('/Zero-pad/');

        new ConfiguredBusinessCalendar(holidays: ['2026-1-1']);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_impossible_holiday_date(): void
    {
        $this->expectException(InvalidBusinessCalendar::class);

        new ConfiguredBusinessCalendar(holidays: ['2026-02-30']);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_holiday_with_a_leading_prefix(): void
    {
        // '12026-01-01' embeds a valid-looking date; anchored matching must refuse it whole
        $this->expectException(InvalidBusinessCalendar::class);

        new ConfiguredBusinessCalendar(holidays: ['12026-01-01']);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_holiday_with_a_trailing_suffix(): void
    {
        $this->expectException(InvalidBusinessCalendar::class);

        new ConfiguredBusinessCalendar(holidays: ['2026-01-015']);
    }

    #[Test]
    public function accepts_a_leap_day_holiday(): void
    {
        // Feb 29 is only valid WITH its year; pins that checkdate receives the captured year, not a
        // positional slip, since month-as-year would refuse the leap day
        $cal = new ConfiguredBusinessCalendar(holidays: ['2028-02-29']);

        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2028-02-29T10:00:00.000000Z')));
    }

    #[Test]
    public function accepts_a_full_day_window_and_a_weekend_market_at_the_boundary(): void
    {
        // 0..24 is the whole day; a Sat–Sun market is legal, 7 being Sunday, the ISO edge
        $cal = new ConfiguredBusinessCalendar(businessDays: [6, 7], openHour: 0, closeHour: 24);

        $this->assertTrue($cal->isBusinessTime(PointInTime::from('2026-06-13T23:30:00.000000Z'))); // Saturday, late
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T10:00:00.000000Z'))); // Monday
    }

    #[Test]
    public function accepts_a_timezone_given_as_a_string(): void
    {
        // the bundle passes the configured tz as a plain string
        $cal = new ConfiguredBusinessCalendar(timezone: 'America/New_York');

        // 14:00 UTC = 10:00 New York in June EDT; inside the local window, outside the UTC one? no:
        // the point is it is judged LOCALLY
        $this->assertTrue($cal->isBusinessTime(PointInTime::from('2026-06-15T14:00:00.000000Z')));
        $this->assertFalse($cal->isBusinessTime(PointInTime::from('2026-06-15T10:00:00.000000Z'))); // 06:00 NY, before open
    }

    // the non-UTC salvo: the LOCAL-hours, DST-safe claims proven, not merely stated

    #[Test]
    #[Group('adversarial')]
    public function business_hours_cross_the_spring_forward_without_losing_an_hour(): void
    {
        // New York, DST starts 2026-03-08. Friday 03-06 16:00 EST, 21:00Z, + 4 business hours:
        // 1h to Friday close, 3h from Monday 09:00 EDT gives Monday 12:00 EDT = 16:00Z, EDT being UTC-4.
        // A UTC-blind or DST-broken walk would land an hour off.
        $cal = new ConfiguredBusinessCalendar(timezone: 'America/New_York');

        $due = $cal->advance(PointInTime::from('2026-03-06T21:00:00.000000Z'), 0, 4);

        $this->assertSame('2026-03-09T16:00:00.000000+00:00', $due->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function business_hours_cross_the_fall_back_without_gaining_an_hour(): void
    {
        // DST ends 2026-11-01. Friday 10-30 16:00 EDT, 20:00Z, + 4 business hours:
        // 1h to close, 3h from Monday 09:00 EST gives Monday 12:00 EST = 17:00Z, EST being UTC-5.
        $cal = new ConfiguredBusinessCalendar(timezone: 'America/New_York');

        $due = $cal->advance(PointInTime::from('2026-10-30T20:00:00.000000Z'), 0, 4);

        $this->assertSame('2026-11-02T17:00:00.000000+00:00', $due->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_holiday_is_matched_in_the_markets_local_day_not_utc(): void
    {
        // 2026-07-03T01:00Z is still July 2nd, 21:00 in New York. +1 business day lands on LOCAL July 3rd,
        // the declared holiday, so it skips through the July-4th weekend to Monday the 6th, keeping the
        // local time-of-day. A UTC-matching walk would have judged the START day the holiday instead.
        $cal = new ConfiguredBusinessCalendar(holidays: ['2026-07-03'], timezone: 'America/New_York');

        $due = $cal->advance(PointInTime::from('2026-07-03T01:00:00.000000Z'), 1, 0);

        $this->assertSame('2026-07-07T01:00:00.000000+00:00', $due->toString()); // Monday 07-06, 21:00 NY
    }

    #[Test]
    #[Group('adversarial')]
    public function business_days_are_judged_on_the_local_weekday_not_utc(): void
    {
        // Friday 20:00 in New York IS Saturday 00:00Z: a UTC-blind walk would treat the start day as a
        // weekend. T+2 business days from local Friday = local Tuesday, time-of-day kept.
        $cal = new ConfiguredBusinessCalendar(timezone: 'America/New_York');

        $due = $cal->advance(PointInTime::from('2026-06-06T00:00:00.000000Z'), 2, 0);

        $this->assertSame('2026-06-10T00:00:00.000000+00:00', $due->toString()); // Tuesday 06-09, 20:00 NY
    }

    private function calendar(): ConfiguredBusinessCalendar
    {
        return new ConfiguredBusinessCalendar; // defaults: Mon–Fri, 09:00–17:00, UTC, no holidays
    }

    #[Test]
    public function hours_that_fit_inside_one_window_land_on_the_same_day(): void
    {
        // the branch that RETURNS mid-window, and the only one that never jumps to another day: without
        // it every advance would fall through to the next open, and a two-hour SLA taken at 10:00 would
        // land tomorrow morning instead of at noon.
        $cal = new ConfiguredBusinessCalendar;

        $at = $cal->advance(PointInTime::fromStorage('2026-06-17 10:00:00+00'), 0, 2);

        $this->assertSame('2026-06-17T12:00:00.000000+00:00', $at->toString());
    }

    #[Test]
    public function hours_that_overflow_the_window_continue_at_the_next_open(): void
    {
        // the sibling branch: 3 hours from 16:00 spends 1 before the 17:00 close and carries 2 into the
        // next business day's window, landing at 11:00, which pins that the comparison decides between
        // "fits" and "carries", not merely which day it is
        $cal = new ConfiguredBusinessCalendar;

        $at = $cal->advance(PointInTime::fromStorage('2026-06-17 16:00:00+00'), 0, 3);

        $this->assertSame('2026-06-18T11:00:00.000000+00:00', $at->toString());
    }

    #[Test]
    public function hours_that_exactly_reach_the_close_stay_on_the_day(): void
    {
        // the boundary between the two: seconds EQUAL to the remaining window still fit, so 17:00 is the
        // answer rather than the next morning
        $cal = new ConfiguredBusinessCalendar;

        $at = $cal->advance(PointInTime::fromStorage('2026-06-17 16:00:00+00'), 0, 1);

        $this->assertSame('2026-06-17T17:00:00.000000+00:00', $at->toString());
    }
}
