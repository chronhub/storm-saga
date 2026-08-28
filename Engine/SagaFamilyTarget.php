<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Throwable;

/**
 * The family-poke role of the saga engine: the one callback a settling member of an indexed spawn
 * family drives on its parent. Engine-internal by audience, like the timer-runner role; nothing but
 * the poke's own handler should call it, and the role exists so nothing else can by accident.
 *
 * @see \Storm\Saga\Child\PokeParentFamily
 */
interface SagaFamilyTarget
{
    /**
     * Spend the crossing this saga's indexed-family gate rested, if it owes one and every family it
     * awaits is now complete. Total and idempotent: a saga that parked nothing, that has left the
     * wait it parked at, that still has members out, or that no longer exists is a plain `false`, and
     * only the poke arriving after the LAST member settles finds work. The counts are re-read from
     * the database inside the step, so the answer cannot be stale by the time it is acted on.
     *
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when the replayed crossing's transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the replayed crossing's drive
     */
    public function pokeFamily(string $workflowType, string $correlationId, ?string $causationId = null): bool;
}
