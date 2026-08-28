<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A `ConfiguredBusinessCalendar` is malformed, a configuration bug caught at CONSTRUCTION on boot,
 * never at the arm. An empty business-day set or an inverted hours window would loop the step's
 * transaction forever, since the calendar walks forward until it finds an open instant that cannot
 * exist; a mis-formatted holiday would silently never match, landing a business deadline on the very
 * day it was told to skip.
 */
final class InvalidBusinessCalendar extends LogicException implements SagaException
{
    public static function emptyBusinessDays(): self
    {
        return new self('BusinessCalendar: businessDays is empty — no day is ever open, a business-time advance could never terminate. Give at least one ISO day (1=Mon … 7=Sun).');
    }

    public static function dayOutOfRange(int $day): self
    {
        return new self(sprintf('BusinessCalendar: businessDays contains %d — days are ISO numbers, 1 (Mon) to 7 (Sun).', $day));
    }

    public static function hoursWindowInvalid(int $openHour, int $closeHour): self
    {
        return new self(sprintf(
            'BusinessCalendar: the hours window [%d, %d) is invalid — it needs 0 <= open < close <= 24. An inverted '
            .'window would make the business-time walk diverge instead of terminate.',
            $openHour, $closeHour,
        ));
    }

    public static function malformedHoliday(string $holiday): self
    {
        return new self(sprintf(
            "BusinessCalendar: holiday \"%s\" is not a valid 'Y-m-d' date — it would silently never match, and the "
            .'deadline would land on the very day it was told to skip. Zero-pad it (e.g. 2026-01-01).',
            $holiday,
        ));
    }
}
