<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * What woke the saga: a `start` command, a delivered domain `event`, a fired per-state timer
 * `stateTimeout`, a back-off retry `kick`, a due schedule slot `schedule`, the instance-wide
 * deadline `globalDeadline`, the dead-letter signal for a saga-issued command `effectFailure`, an
 * operator's `cancel`, an application-level `user` signal, or the system's own `familyPoke`, sent by
 * a spawned member's terminal settle so its parent can spend a crossing an indexed family's gate
 * rested while that member still ran. A `user` signal is a payload delivered to a live saga that
 * updates its vars and issues commands without transitioning: the event/signal split, where an event
 * drives the state machine and a signal nudges the resting state.
 *
 * @see Signal
 */
enum SignalKind
{
    case Start;

    case Event;

    case StateTimeout;

    case Kick;

    case Schedule;

    case GlobalDeadline;

    case EffectFailure;

    case Cancel;

    case User;

    case FamilyPoke;
}
