<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Calendar\ConfiguredBusinessCalendar;

/**
 * A state declares a business-time deadline via `#[WaitFor(deadlineBusinessDays:|deadlineBusinessHours:)]`
 * but the engine has no {@see BusinessCalendar} bound to resolve it. DELIBERATELY loud: nothing binds a
 * calendar by default; a deadline computed on an implicit fictitious market of Mon-Fri 9-17 UTC with
 * zero holidays would be silently-wrong money-path config. The app opts in by enabling
 * `storm.saga.calendar`, so the bundle registers {@see ConfiguredBusinessCalendar} with the DECLARED
 * market, or by registering its own implementation.
 */
final class BusinessCalendarMissing extends LogicException implements SagaException
{
    public static function forArm(string $stateKey): self
    {
        return new self(sprintf(
            'State "%s" declares a business-time deadline but no BusinessCalendar is bound to resolve it. '
            .'Enable storm.saga.calendar (declaring your market: days, hours, holidays, timezone) or register '
            .'your own BusinessCalendar implementation — nothing is bound by default on purpose: an implicit '
            .'fictitious market would compute silently-wrong deadlines.',
            $stateKey,
        ));
    }
}
