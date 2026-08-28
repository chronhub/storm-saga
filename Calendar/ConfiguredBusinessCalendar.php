<?php

declare(strict_types=1);

namespace Storm\Saga\Calendar;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\Exception\InvalidBusinessCalendar;

/**
 * The configured default `BusinessCalendar`, Storm's out-of-the-box calendar: a single market described
 * by its business days, daily business hours `[open, close)`, holiday set, and timezone.
 *
 * Business hours are LOCAL to `$timezone`; a 9-17 bank closes at 17:00 in its own zone, not UTC. The
 * arithmetic runs in local time and converts the result back to UTC, and it is DST-safe: day steps are
 * wall-clock calendar days, hour steps are elapsed seconds.
 *
 * The construction VALIDATES the market shape via {@see InvalidBusinessCalendar}: an empty day set or an
 * inverted hours window would make the walk diverge inside the step's transaction, holding the fence and
 * pinning the connection, and a mis-formatted holiday would silently never match. All fail at boot
 * instead.
 */
final readonly class ConfiguredBusinessCalendar implements BusinessCalendar
{
    private DateTimeZone $timezone;

    /**
     * @param  list<int>  $businessDays  ISO day numbers 1=Mon to 7=Sun that are business days
     * @param  list<string>  $holidays  'Y-m-d' dates in `$timezone` that are non-business
     *
     * @throws InvalidBusinessCalendar when the day set is empty or out of 1..7, the hours window is
     *                                 not 0 <= open < close <= 24, or a holiday is not a valid 'Y-m-d'
     */
    public function __construct(
        private array $businessDays = [1, 2, 3, 4, 5],
        private int $openHour = 9,
        private int $closeHour = 17,
        private array $holidays = [],
        DateTimeZone|string $timezone = 'UTC',
    ) {
        if ($businessDays === []) {
            throw InvalidBusinessCalendar::emptyBusinessDays();
        }
        foreach ($businessDays as $day) {
            if ($day < 1 || $day > 7) {
                throw InvalidBusinessCalendar::dayOutOfRange($day);
            }
        }
        if ($openHour < 0 || $openHour >= $closeHour || $closeHour > 24) {
            throw InvalidBusinessCalendar::hoursWindowInvalid($openHour, $closeHour);
        }
        foreach ($holidays as $holiday) {
            // equivalent mutant: the year index $m[1] to $m[0]; the full match starts with the year,
            // so (int) of either yields the same number; the capture is kept for intent, not behavior
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $holiday, $m) !== 1
                || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw InvalidBusinessCalendar::malformedHoliday($holiday);
            }
        }

        $this->timezone = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;
    }

    public function advance(PointInTime $from, int $businessDays, int $businessHours): PointInTime
    {
        $cursor = $this->toLocal($from);
        try {
            $cursor = $this->addBusinessDays($cursor, $businessDays);
            $cursor = $this->addBusinessSeconds($cursor, $businessHours * 3600);
            // unreachable by construction: all modify() args are hardcoded '+1 day' or int-derived "+$seconds seconds"
            // @codeCoverageIgnoreStart
        } catch (DateMalformedStringException $e) {
            throw new InvalidDateTimeException('Failed to advance business time: '.$e->getMessage(), 0, $e);
        }
        // @codeCoverageIgnoreEnd

        return PointInTime::fromDateTime($cursor);
    }

    public function isBusinessTime(PointInTime $at): bool
    {
        $local = $this->toLocal($at);

        return $this->isBusinessDay($local) && $this->withinHours($local);
    }

    private function toLocal(PointInTime $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone($this->timezone);
    }

    private function isBusinessDay(DateTimeImmutable $local): bool
    {
        return in_array((int) $local->format('N'), $this->businessDays, true)
            && ! in_array($local->format('Y-m-d'), $this->holidays, true);
    }

    private function withinHours(DateTimeImmutable $local): bool
    {
        // @infection-ignore-all; equivalent: 'G' is always a numeric string from "0" to "23", and the >=/< below
        // compare it numerically with or without the cast; the (int) is kept for type clarity, not behavior
        $hour = (int) $local->format('G');

        return $hour >= $this->openHour && $hour < $this->closeHour;
    }

    /**
     * Advance `$n` whole business days: each step moves a calendar day forward and skips weekends/holidays,
     * keeping the time-of-day; T+2 business days from Friday 14:00 is Tuesday 14:00.
     *
     * @throws DateMalformedStringException when `$cursor` is not a valid date
     */
    private function addBusinessDays(DateTimeImmutable $cursor, int $n): DateTimeImmutable
    {
        for ($i = 0; $i < $n; $i++) {
            $cursor = $cursor->modify('+1 day');
            while (! $this->isBusinessDay($cursor)) {
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $cursor;
    }

    /**
     * Consume `$seconds` of elapsed business time: roll into a business window if outside one, then spend
     * seconds within each day's window, jumping to the next business day's open when a day's window runs out.
     *
     * @throws DateMalformedStringException when `$cursor` is not a valid date
     */
    private function addBusinessSeconds(DateTimeImmutable $cursor, int $seconds): DateTimeImmutable
    {
        if ($seconds <= 0) {
            return $cursor; // no hours to add; keep the time-of-day from the days advance
        }

        $cursor = $this->rollToBusinessWindow($cursor);

        while (true) {
            $close = $cursor->setTime($this->closeHour, 0);
            $secondsToClose = $close->getTimestamp() - $cursor->getTimestamp();

            if ($seconds <= $secondsToClose) {
                return $cursor->modify("+$seconds seconds");
            }

            $seconds -= $secondsToClose;
            $cursor = $this->nextOpen($close);
        }
    }

    /**
     * Move `$cursor` to the start of a business window: today's open if before it,
     * the next open if past close / off-day.
     *
     * @throws DateMalformedStringException when `$cursor` is not a valid date
     */
    private function rollToBusinessWindow(DateTimeImmutable $cursor): DateTimeImmutable
    {
        if ($this->isBusinessDay($cursor)) {
            // @infection-ignore-all; equivalent: 'G' is a numeric string, the `<` comparisons below are numeric either way
            $hour = (int) $cursor->format('G');

            // the two `<` below carry `<=` mutants proven equivalent: at hour==openHour, setTime(open) is a no-op;
            // at hour==closeHour, addBusinessSeconds's loop absorbs a cursor-at-close, where secondsToClose=0 advances to nextOpen.
            // Left UN-ignored on purpose: their `>` siblings ARE killed by tests, and an ignore would mask those too.
            if ($hour < $this->openHour) {
                return $cursor->setTime($this->openHour, 0);
            }
            if ($hour < $this->closeHour) {
                return $cursor; // already inside the window
            }
        }

        return $this->nextOpen($cursor);
    }

    /**
     * The next business day's open instant, strictly after `$from`'s day.
     *
     * @throws DateMalformedStringException when `$from` is not a valid date
     */
    private function nextOpen(DateTimeImmutable $from): DateTimeImmutable
    {
        $day = $from->modify('+1 day');
        while (! $this->isBusinessDay($day)) {
            $day = $day->modify('+1 day');
        }

        return $day->setTime($this->openHour, 0);
    }
}
