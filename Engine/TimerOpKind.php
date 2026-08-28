<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * The kind of timer instruction the machine, or a performer, collects: arm a per-state timeout, arm
 * a back-off kick, arm the instance-wide global deadline, arm a recurring schedule slot, the one
 * absolute-instant kind, cancel a state's timers, or cancel the global deadline.
 *
 * @see TimerOp
 */
enum TimerOpKind
{
    case ArmTimeout;

    case ArmKick;

    case ArmGlobal;

    case ArmSchedule;

    case CancelState;

    case CancelGlobal;
}
