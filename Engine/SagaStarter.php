<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Throwable;

/**
 * The birth role of the saga engine: bring an instance into existence, idempotently, under the
 * guarded correlation namespace. A consumer that only starts sagas, a spawner or a triggering
 * handler, asks for this role and never sees a timer callback or an operator verb.
 */
interface SagaStarter
{
    /**
     * Start an instance of `$workflowType` correlated by `$correlationId`. Idempotent: a second start
     * for the same id is a no-op.
     *
     * The correlation namespace is guarded: an id containing the reserved child delimiter is refused
     * unless the context declares the parent that mints it, so a child saga can only be born through
     * a spawn; a native start can never adopt, or collide with, a child identity.
     *
     * @param  array<string, mixed>  $vars  initial state bag
     * @param  array<string, mixed>  $context  ambient context handed to activities, such as actor or origin
     *
     * @throws InvalidChildIdentity when the correlation trespasses on the reserved child namespace, or a declared parent does not mint it
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowStepLimitExceeded when the start's synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the start's drive
     */
    public function start(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool;

    /**
     * Start an instance the consumer-safe way: `start` with the fence seam exposed. When a concurrent
     * step holds the saga's fence, this throws `SagaFenceBusy`, retryable, instead of collapsing to
     * `false`. A start message consumed off the bus, a command handler that starts a saga, MUST use
     * this, never `start`: a fence-busy collapsed to `false` is acked and the intended start is lost
     * if the competing transaction later rolls back. The idempotent already-started semantics are
     * kept: a second start for the same id stays a quiet `false`, never a throw, since the instance
     * exists and a redelivery cannot help. `start`'s bool sugar is only for a synchronous caller that
     * genuinely treats "fence busy now" as a meaningful false.
     *
     * @param  array<string, mixed>  $vars  initial state bag
     * @param  array<string, mixed>  $context  ambient context handed to activities, such as actor or origin
     *
     * @throws InvalidChildIdentity when the correlation trespasses on the reserved child namespace, or a declared parent does not mint it
     * @throws SagaFenceBusy when a concurrent step holds the fence; retry the start
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowStepLimitExceeded when the start's synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the start's drive
     */
    public function startOrThrow(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool;

    /**
     * The atomic signal-with-start: start the saga when no instance holds the correlation, then
     * deliver `$signal`: one fence, one transaction, no window between the two where a competing
     * step could advance the saga or the second half could be lost. Use it where `startOrThrow()`
     * followed by a signal would race its own gap.
     *
     * The signal half keeps the signaller role's contract: it only nudges a RUNNING saga, so a birth
     * whose synchronous drive settles never sees it, and an undeclared handler drops it, the start
     * alone applying. Fence contention throws, the consumer-safe seam, so a bus-consumed trigger is
     * redelivered rather than acked as a false no-op.
     *
     * @param  array<string, mixed>  $vars  initial state bag, used only when this call births the saga
     * @param  array<string, mixed>  $context  ambient birth context, used only when this call births the saga
     *
     * @throws SagaFenceBusy when a concurrent step holds the fence; retryable
     * @throws InvalidChildIdentity when the correlation trespasses on the reserved child namespace, or a declared parent does not mint it
     * @throws WorkflowNotFound when no version of the workflow type is registered
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable when the start's drive, the handler, or the step throws, re-thrown
     */
    public function startOrSignal(string $workflowType, string $correlationId, object $signal, array $vars = [], array $context = [], ?string $causationId = null): bool;
}
