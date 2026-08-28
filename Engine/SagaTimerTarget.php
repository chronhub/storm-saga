<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
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
 * The timer-runner role of the saga engine: the callbacks a claimed due timer drives, one verb per
 * `TimerKind`. Engine-internal by audience; nothing but the timer runner should call these, and the
 * role exists so nothing else can by accident.
 */
interface SagaTimerTarget
{
    /**
     * Fire a state's timeout. `$expectedStateKey` is the stale-state guard, ignored if the instance
     * already left that state after a timer raced a transition.
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
     * @throws Throwable when any other exception is thrown by the timeout's drive
     */
    public function timeout(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool;

    /**
     * Re-run a state, a back-off kick the timer runner fires after a failed activity. Like a timeout
     * but not a timeout: the state's runner runs fresh and the activity is retried.
     * `$expectedStateKey` is the stale-state guard.
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
     * @throws Throwable when any other exception is thrown by the kick's drive
     */
    public function kick(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool;

    /**
     * Fire a schedule state's due slot, a `Schedule` timer the runner claimed, due at `$dueAt`. Run
     * the tick, then re-arm the next slot. The tick:
     *
     * - Counts the slots missed since `$dueAt`;
     * - Applies the catch-up;
     * - Drives the `schedule` edge.
     *
     * `$expectedStateKey` is the stale-state guard, a no-op if the saga already left the schedule
     * state.
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
     * @throws Throwable when any other exception is thrown by the schedule's drive
     */
    public function schedule(string $workflowType, string $correlationId, string $expectedStateKey, PointInTime $dueAt, ?string $causationId = null): bool;

    /**
     * Fire a saga's global deadline, a `Global` timer the runner claimed: force it to the workflow's
     * `onGlobalTimeout`, or halt, wherever it currently sits. Instance-wide, with no state guard.
     *
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when the onGlobalTimeout drive's transition chain cycles
     * @throws UnknownState when `onGlobalTimeout`, or a transition from it, targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a timer's fire instant cannot be derived
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the global timeout's drive
     */
    public function globalTimeout(string $workflowType, string $correlationId, ?string $causationId = null): bool;
}
