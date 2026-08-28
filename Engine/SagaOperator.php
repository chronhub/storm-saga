<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Throwable;

/**
 * The operator role of the saga engine: the verbs that act ON a saga rather than deliver TO it,
 * cancellation, the dead-lettered effect's settle, and the state migration sweep. The console and
 * ops surfaces ask for this role; application flow never needs it.
 */
interface SagaOperator
{
    /**
     * Cancel a saga on an operator's word: halt it where it sits and roll back what is safe to undo.
     * Eligibility is positional; an unconfirmed in-flight step is skipped and flagged, never blindly
     * compensated. Refused with `false` at an effect-gating wait unless `$force`, since an in-flight
     * effect is never discarded on a word alone: retry after the outcome lands, or own the risk
     * explicitly. Also `false` when no instance exists, or it is already settled.
     *
     * @throws WorkflowNotFound when no workflow is registered under `$workflowType`
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws SerializationExceptionContract when a compensation's issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the cancel step's compensation
     */
    public function cancel(string $workflowType, string $correlationId, ?string $reason = null, bool $force = false, ?string $causationId = null): bool;

    /**
     * Signal that a saga-issued command was dead-lettered, either because consumer poison exhausted
     * retries or the saga outbox relay gave up dispatch. Whether it SETTLES depends on what is known
     * about the effect: a dead-letter alone is not proof that the handler transaction rolled back. Only
     * a command whose non-commit was established, never delivered to a handler at all or issued to one
     * that signed `#[TransactionalHandler]`, halts the saga at its effect-gating wait and compensates
     * the earlier confirmed steps. Otherwise the saga escalates and stays alive, since rolling back
     * around an effect that may have landed is how money is created. No-op when no saga matches the correlation, when the saga is past the
     * effect-gating wait because the outcome arrived another way, or when it already settled.
     *
     * Returns the full {@see ExecutionReport}: the settle signal is one-shot on the listener path, so
     * the caller must see a held fence, `FenceBusy`, to retry locally instead of losing the signal in
     * a collapsed `false`; the durable `failed` outbox row backs it up either way, and the cleanup
     * re-derives.
     *
     * @throws WorkflowNotFound when the resolved type is not registered, a stale row in the store
     * @throws WorkflowVersionNotFound when the instance's pinned version was purged while it still runs
     * @throws StaleWorkflowInstance when the OCC update loses to a competing step
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws SerializationExceptionContract when a compensation's issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws Throwable when any other exception is thrown by the failed-issued-effect step's compensation
     */
    public function failIssuedEffect(string $correlationId, ?string $causationId = null, ?string $failedMessageId = null): ExecutionReport;

    /**
     * Migrate one instance's stored state to the declared `stateVersion` without running a step: the
     * sweep's per-row verb, riding the same fence, OCC update, migration chain and declared validator
     * a step uses. True when the row moved; false when it was absent, already current, or fence-busy,
     * the latter because the live step holding it migrates lazily anyway.
     */
    public function migrateState(string $workflowType, string $correlationId): bool;
}
