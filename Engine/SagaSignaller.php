<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\WorkflowNotFound;
use Throwable;

/**
 * The nudge role of the saga engine: deliver application-level signal objects to a live saga
 * without transitioning it. This is the event/signal split's signal half; a consumer that only
 * signals asks for this role.
 */
interface SagaSignaller
{
    /**
     * Deliver an application-level signal object to a live saga; the saga stays at its state with no
     * transition. The workflow's declared handler for the object's class runs with the current vars
     * and returns the new bag plus optional commands. This is the event/signal split: an event
     * drives the state machine, a signal nudges the resting state. A saga with no declared handler
     * drops the signal with a `Skip` reason and no buffering; one that is not running is skipped.
     * Returns whether it applied.
     *
     * @throws WorkflowNotFound when no version of the workflow type is registered
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable when the handler or the step throws, re-thrown
     */
    public function signal(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): bool;

    /**
     * The answering signal: deliver an application-level signal object like `signal()`, and hand back
     * the handler's typed reply from the SAME run; the durable mutation and the answer are one
     * handler invocation under the fence, so there is no signal-then-reread and no race between them.
     * Null when the handler answered nothing, or when the signal did not apply at all, which covers
     * an undeclared handler, a saga that is not running, and an absent saga; `SagaFenceBusy` when a
     * concurrent step holds the fence, thrown rather than collapsed to null, since "busy, retry" and
     * "no answer" are different facts.
     *
     * THE VISIBILITY CONTRACT: the reply is worth what the caller's transaction is worth. On a
     * top-level call the step commits before the reply returns. Under an ambient DBAL transaction,
     * an inbox consumer wrapping its handlers, the step nests as a SAVEPOINT: the reply exists
     * before the outer commit makes the mutation durable, the same window the engine's announcements
     * already tolerate. A caller acting on the reply outside its own transaction must not treat it
     * as committed truth; a rolled-back step never surfaces a reply at all.
     *
     * A signal is still not a request/response protocol: the saga stays at its state, nothing
     * transitions, and modeling the process around synchronous answers is the regression the module
     * refuses. Reach for this where the caller genuinely needs the post-mutation value, an
     * incremental authorization's new available amount, not as the default signaling verb.
     *
     * @throws SagaFenceBusy when a concurrent step holds the fence; retryable
     * @throws WorkflowNotFound when no version of the workflow type is registered
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable when the handler or the step throws, re-thrown; a thrown step yields no reply
     */
    public function signalFor(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): ?object;
}
