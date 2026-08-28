<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * Why a step did nothing, the silent `return`s of the engine made nameable. The engine's public
 * surface still reports a plain `false`, since a Skip is not an error; the reason exists for the
 * policy's table test. One reason has a voice beyond the bool: `InFlightEffect`, announced by the
 * executor as `SagaCancelRefused`, the only skip that leaves behind a living saga someone asked to
 * die; every other reason is a benign race absorbed quietly.
 *
 * @see Skip
 */
enum SkipReason
{
    /** No instance exists under this identity, such as an event whose correlation matches no saga. */
    case NotFound;

    /** The instance already settled, whether completed, halted, or compensated; late signals are no-ops. */
    case AlreadySettled;

    /** A second `start` for an existing instance; start is idempotent. */
    case AlreadyStarted;

    /** The timer's state was left before it fired, the timer raced a transition. */
    case StaleState;

    /** A `Global` timer fired for a workflow that declares no global deadline, a phantom timer. */
    case NoGlobalDeadline;

    /** A dead-letter signal for a saga no longer parked at its effect-gating wait. */
    case PastGatingWait;

    /**
     * A cancel at an effect-gating wait without `--force`; an in-flight effect is never discarded.
     * The one announced skip: the executor voices it as `SagaCancelRefused`.
     */
    case InFlightEffect;

    /**
     * A straggler timer, claimed before the waive committed, fired at a saga whose global cap was
     * waived; the cap is spent and the saga is quiet by design, so re-arming would resurrect the
     * churn the waive killed. Benign: the row's `waived_at` is the durable truth the straggler reads.
     */
    case CapWaived;

    /** A user signal arrived but the workflow declares no handler for its class; dropped with a reason, no buffering. */
    case NoSignalHandler;

    /**
     * A family poke reached a saga that owes no crossing: it never rested a conclusion, or it already
     * spent the one it rested. The ordinary answer, since every member's terminal settle pokes and
     * only the last one can find work.
     */
    case NothingParked;

    /**
     * A family poke reached a saga that owes a crossing whose families are not all complete yet: a
     * sibling still runs, or the poke of an earlier member is only arriving now. Benign, and the
     * poke of whichever member settles last is what will find the work.
     */
    case FamilyIncomplete;

    /**
     * An event delivered during a declared birth delay: the saga is born but its first drive is
     * still deferred, and waking the undriven start state would run the very effect the delay
     * defers. Early, not lost: the executor reports it `NotYetApplicable`, so the transport
     * redelivers and the event lands once the due has passed.
     */
    case BirthDelayPending;
}
