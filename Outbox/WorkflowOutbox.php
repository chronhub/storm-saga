<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Message;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\OutboxEntry;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Workflow\CompensationRecord;

/**
 * The engine-facing outbox: seals the outgoing command per the hop protocol, then hands the finished
 * `Message` to the storage port. The engine's only path to outbox storage runs through this front, so
 * a command cannot reach a row unsealed, structurally rather than by documentation, and the executor
 * stays blind to what a command looks like on the wire.
 *
 * Pass-through otherwise: the port below belongs to the saga's co-transactional group per `SagaStepUnitOfWork` law
 * 1, and the adapter's writes still enlist in the step's unit of work; nothing here opens, joins, or
 * commits a transaction.
 *
 * @see HopProtocol what a sealed command carries, and why
 * @see WorkflowOutboxWriter the storage port underneath
 */
final readonly class WorkflowOutbox
{
    public function __construct(
        private HopProtocol $protocol,
        private WorkflowCommandStore $writer,
    ) {}

    /**
     * @throws SagaStorageFailure when the storage fails, forwarded from the port
     * @throws SerializationExceptionContract when the command is not a serializable payload, a wiring bug surfaced not wrapped
     * @throws InvalidMessageException when the command is itself a Message, a caller bug; see `HopProtocol::seal()`
     */
    public function write(WorkflowId $id, object $command, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void
    {
        $this->writer->write($id, $this->protocol->seal($id, $command), $issuedFromState, $issuedAtVersion, $generation, $purpose, $effectGroup);
    }

    /**
     * Seal a command for this saga's hop without writing it, for a step that writes its commands in one
     * statement with the rest of its effects.
     */
    public function seal(WorkflowId $id, object $command): Message
    {
        return $this->protocol->seal($id, $command);
    }

    /**
     * Write an already sealed command, the entry a step folded before writing.
     */
    public function writeEntry(WorkflowId $id, OutboxEntry $entry): void
    {
        $this->writer->write($id, $entry->message, $entry->issuedFromState, $entry->issuedAtVersion, $entry->generation, $entry->purpose, $entry->effectGroup);
    }

    /**
     * The abort's recall; see the port's contract for what it touches and what it leaves.
     *
     * @param  list<CompensationRecord>  $spared  the undone entries, whose forward rows stay pending
     * @return int the number of rows recalled
     *
     * @throws SagaStorageFailure when the storage fails, forwarded from the port
     */
    public function cancelPending(WorkflowId $id, int $generation, array $spared): int
    {
        return $this->writer->cancelPending($id, $generation, $spared);
    }

    /**
     * The arm's proving recall; see the port's contract for what a positive count proves.
     *
     * @return int the number of rows recalled, the proof of a non-event when positive
     *
     * @throws SagaStorageFailure when the storage fails, forwarded from the port
     */
    public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int
    {
        return $this->writer->recallUndispatched($id, $generation, $issuedFromState, $effectGroup);
    }

    /**
     * The rollback's proving read; see the port's contract for what it locks and reports.
     *
     * @return list<bool> one flag per row, in `id` order, true when a relay claimed that row at least once
     *
     * @throws SagaStorageFailure when the storage fails, forwarded from the port
     */
    public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array
    {
        return $this->writer->forwardClaims($id, $generation, $issuedFromState, $effectGroup);
    }
}
