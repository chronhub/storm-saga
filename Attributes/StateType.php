<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

/**
 * The kind of state a State attribute declares: an `Activity` runs work, a `Wait` parks for an event,
 * a `Final` ends the saga, and a `Schedule` re-fires on a recurring cadence, interval, or cron; a
 * durable state that ticks, never a saga that loops in memory.
 *
 * @see State
 */
enum StateType: string
{
    case Activity = 'activity';

    case Wait = 'wait';

    case Final = 'final';

    case Schedule = 'schedule';
}
