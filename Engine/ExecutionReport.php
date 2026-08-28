<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * What one fenced step actually did, the executor's report, richer than the public bool so the
 * delivery seams can react per-cause:
 *
 * - `Applied`: the step changed something, whether it started, advanced, escalated, enforced,
 *   settled, or canceled.
 *
 * - `NothingToDo`: the policy skipped, or a non-event signal moved nothing, so re-delivering would
 *   not help.
 *
 * - `NotYetApplicable`: a delivered event found its instance alive yet nothing consumed it; the
 *   event arrived ahead of the wait that matches it, so a redelivery lands once the saga advances.
 *   The event router turns this into a retryable throw, symmetric to `FenceBusy`; skips and settled
 *   instances stay `NothingToDo`, where a redelivery cannot help.
 *
 * - `NeverApplicable`: a delivered event found its instance alive, nothing consumed it, and the
 *   definition proves nothing ever will, since no wait still reachable from the resting state
 *   accepts that class. The twin of `NotYetApplicable` and its opposite remedy: a redelivery cannot help,
 *   so the router drops it instead of throwing, and the engine says so out loud once rather than
 *   letting the message churn its retry budget into the dead-letter transport. Reached by an event
 *   class shared with another workflow, or by a duplicate of a wait the saga has left for good.
 *
 * - `FenceBusy`: a concurrent step holds this saga's fence, so the signal was not applied and a
 *   retry will help. The distinction matters because the two `false` causes have opposite remedies:
 *   the OCC race is safe-by-throw, `StaleWorkflowInstance` then retry, while a bare `false` would
 *   leave the fence race silent. This report removes the asymmetry; the event router turns
 *   `FenceBusy` into a retryable throw, while timers keep their lease as their retry.
 *
 * `Engine`'s public methods collapse this to the bool apps expect; the report stays the framework's
 * internal seam.
 */
enum ExecutionReport
{
    case Applied;

    case NothingToDo;

    case NotYetApplicable;

    case NeverApplicable;

    case FenceBusy;

    public function applied(): bool
    {
        return $this === self::Applied;
    }
}
