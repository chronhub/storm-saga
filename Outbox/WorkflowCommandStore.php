<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Message;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Workflow\CompensationRecord;

/**
 * The step's command capability: persist one sealed outgoing message atomically with the state
 * advance, and recall what has not left. The sealing front `WorkflowOutbox` is the only caller; the
 * dead-letter half is `FailedWorkflowCommands`. Writes MUST enlist in the step's single unit of work
 * per `SagaStepUnitOfWork` law 1.
 *
 * Two recalls, never one count read two ways: the abort's recall stops what an aborting run no longer
 * wants sent, and the arm's recall proves a command never left. Both are single updates the relay's
 * claim arbitrates. A row a relay is claiming right now is row-locked, so the update blocks on it and
 * re-evaluates it once the claim commits; a claimed row carries a claim marker, which the proving
 * recall never counts and whose running lease keeps the abort's recall off the row while the relay
 * publishes it. The race is decided by the store and never lost.
 *
 * A rollback reads the same marker before it undoes a step, through `forwardClaims()`, whose row locks
 * keep what it found unclaimed out of every claim until the step commits.
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
     * `$purpose` has no default for the same reason: `Forward` would look safe and hand an undo to the
     * next abort's recall.
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     * @throws SerializationExceptionContract when the message wraps a non-serializable payload, a wiring bug surfaced rather than wrapped as a storage failure
     */
    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void;

    /**
     * The abort's recall: cancel the forward rows of run `$generation` still `pending`, the commands an
     * aborting saga no longer wants sent. Called when an instance settles by ABORTING, halted or rolled
     * back, never on a normal completion whose pending commands may be legitimate fire-and-forget.
     *
     * It touches forward rows of that run alone: an undo, a cascade or a poke is owed by the saga and
     * survives, and another run's rows belong to that run. A row under a claim whose lease still runs is
     * left alone, since the relay may be publishing it right now; a row the relay tried and released is
     * still recalled, which stops its next attempt and proves nothing about the last one.
     *
     * `$spared` lists the undone entries of the saga's log, whose forward rows are left `pending`: the
     * undo already issued for each pairs with that do and tolerates arriving first, while a recalled do
     * could leave the undo reversing an effect that never happens. It has no default for the reason
     * `$purpose` has none: an empty list would look safe and recall the do an undo was issued for.
     *
     * @param  list<CompensationRecord>  $spared
     * @return int the number of rows recalled
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function cancelPending(WorkflowId $id, int $generation, array $spared): int;

    /**
     * The arm's proving recall: cancel the forward rows of run `$generation` that `$issuedFromState`
     * issued under `$effectGroup`, still `pending` and never claimed by a relay. The count is the proof
     * a race victory or a join failure disposes of an arm by: above zero, the arm's command never left
     * and nothing needs undoing; zero, the command may have reached a handler and the arm is undone
     * instead. A row the relay tried and released stays pending, since recalling a command whose
     * attempt may have landed would leave that effect without its undo.
     *
     * @return int the number of rows recalled, the proof of a non-event when positive
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int;

    /**
     * The rollback's proving read: report, for each forward row of run `$generation` that
     * `$issuedFromState` issued under `$effectGroup`, null for the ungrouped rows, whatever its status,
     * whether a relay ever claimed it. The rows no relay claimed are locked until the step commits, and
     * a relay's claim skips a locked row, so a row read unclaimed here is still unclaimed when the
     * abort's recall cancels it. A claimed row is read, never locked: its marker never goes back to
     * NULL, and a relay batch may still hold it.
     *
     * @return list<bool> one flag per row, in `id` order, true when a relay claimed that row at least once
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array;
}
