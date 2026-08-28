<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaOutcomeNotYetApplicable;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Throwable;

/**
 * The delivery role of the saga engine: hand a waiting saga what just happened. The bool variants
 * are app-facing sugar; `routeOutcome()` is the transport-safe seam a consumer-acked handler must
 * take, its retryable throws turning a fence race or an early arrival into a redelivery.
 */
interface SagaOutcomeDelivery
{
    /**
     * Deliver an event to a waiting instance. No-op if it doesn't match a transition.
     *
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the deliver event's drive
     */
    public function deliver(string $workflowType, string $correlationId, object $event, ?string $causationId = null): bool;

    /**
     * Deliver an event to the saga identified by its `correlationId` alone. The instance store
     * resolves the `workflowType` from the correlation, then the usual `deliver` runs: matcher,
     * transition, drive. No-op when no saga matches the correlation, an event whose `correlationId`
     * belongs to no saga.
     *
     * This is the app-facing bool variant; a held fence, and an event arrived ahead of its wait,
     * collapse to `false` here. The framework's
     * own generic router takes `routeOutcome()` instead, which exposes the fence seam: a held fence
     * throws {@see SagaFenceBusy}, retryable. A consumer-acked event handler MUST use `routeOutcome()`,
     * never this: a fence-busy collapsed to `false` is acked and dropped with no redelivery, a silent
     * loss of the outcome event. The bool here is only for a synchronous caller that genuinely treats
     * "fence busy now" as a meaningful false.
     *
     * @throws WorkflowNotFound when the resolved type is not registered, a stale row in the store
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the deliver event's drive
     */
    public function deliverByCorrelation(string $correlationId, object $event, ?string $causationId = null): bool;

    /**
     * The event router's delivery path: `deliverByCorrelation` with the fence seam exposed. When a
     * concurrent step holds the saga's fence, this throws `SagaFenceBusy`, retryable, instead of
     * collapsing to `false`. An acked-but-unapplied outcome event would otherwise be lost forever in
     * the silent window of the fence race; the throw makes Messenger redeliver, and the stale-guard
     * and consumer dedup absorb any duplicate. App code keeps the bool sugar of `deliver` and
     * `deliverByCorrelation`; this is the framework seam.
     *
     * The early-arrival window gets the same treatment: an event that finds its instance alive with
     * nothing to consume it, arrived ahead of the wait that matches it, throws
     * `SagaOutcomeNotYetApplicable`, retryable, and the redelivery lands once the saga advances. A
     * foreign correlation and a settled instance stay a quiet `false`; there a redelivery cannot
     * help.
     *
     * @throws SagaFenceBusy when a concurrent step holds the fence; retry the delivery
     * @throws SagaOutcomeNotYetApplicable when the event arrived ahead of the wait that consumes it; retry the delivery
     * @throws WorkflowNotFound when the resolved type is not registered, a stale row in the store
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the route outcome step's drive
     */
    public function routeOutcome(string $correlationId, object $event, ?string $causationId = null): bool;
}
