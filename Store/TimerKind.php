<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

/**
 * The `kind` of a `workflow_timers` row: a state `Timeout` to fire, a back-off `Kick` to re-run a step,
 * the instance-wide `Global` deadline, or a recurring `Schedule` slot; one table for all kinds. The
 * string the column stores.
 *
 * A `Global` timer is armed once at start under a reserved state key, survives state transitions, and
 * fires the workflow's `onGlobalTimeout` regardless of where the saga currently sits. A `Schedule`
 * timer is armed at an absolute calendar slot and re-armed via upsert to the next slot each time it fires.
 */
enum TimerKind: string
{
    case Timeout = 'timeout';

    case Kick = 'kick';

    case Global = 'global';

    case Schedule = 'schedule';
}
