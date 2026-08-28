<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Store\WorkflowId;

/**
 * The step's command capability: persist one sealed outgoing message atomically with the state
 * advance, and recall what the relay never claimed. The sealing front `WorkflowOutbox` is the only
 * caller; the dead-letter half is `FailedWorkflowCommands`. Writes MUST enlist in the step's single
 * unit of work per `SagaStepUnitOfWork` law 1.
 *
 * @see FailedWorkflowCommands
 * @see SagaStepUnitOfWork
 * @see WorkflowOutbox
 */
interface WorkflowCommandStore
{
    /**
     * Persist the sealed `$message`, tagged with the provenance that later pairs a dead-letter settle to
     * it.
     *
     * `$issuedFromState` and `$issuedAtVersion` are the row's PROVENANCE: the state whose run issued the
     * command, and the instance's OCC version at that step, a step marker unique per step and distinct
     * across a cycle's re-visits. Written once, read back by the dead-letter half's `provenance()` to
     * pair a settle with the exact command that died. `$generation` seals the row to the RUN that issued
     * it, so under a reusing correlation a past run's dead-letter cannot be paired against the living
     * instance. It has no default ON PURPOSE: `1` would look safe and silently seal a later run's
     * command to the first, where `evidence`'s default of `Unknown` is genuinely the conservative
     * reading. A default belongs to a parameter whose safe value is a constant; a generation's never is.
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     * @throws SerializationExceptionContract when the message wraps a non-serializable payload, a wiring bug surfaced rather than wrapped as a storage failure
     */
    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, ?string $effectGroup = null): void;

    /**
     * The settle's recall: cancel every still-`pending` row of this saga. Called when an instance settles
     * by ABORTING, halted or rolled back, never on a normal completion whose pending commands may be
     * legitimate fire-and-forget. The relay never claimed a pending row, so it is still recallable, and
     * mowing it shrinks a forced cancel's residual risk to commands genuinely published. Rows the relay
     * already claimed are untouched: the relay holds their row locks via `FOR UPDATE SKIP LOCKED`, so
     * this update blocks then re-evaluates them as no-longer-pending; the store arbitrates the race,
     * never lost. Runs inside the step's unit of work in the co-transactional group, BEFORE the settle
     * writes the compensation's own commands, which survive by ordering.
     *
     * @return int the number of rows recalled
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function cancelPending(WorkflowId $id, ?string $effectGroup = null): int;
}
