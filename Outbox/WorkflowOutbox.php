<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowId;

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
    public function write(WorkflowId $id, object $command, string $issuedFromState, int $issuedAtVersion, int $generation, ?string $effectGroup = null): void
    {
        $this->writer->write($id, $this->protocol->seal($id, $command), $issuedFromState, $issuedAtVersion, $generation, $effectGroup);
    }

    /**
     * The settle's recall; see the port's contract for the row-lock arbitration.
     *
     * @return int the number of rows recalled
     *
     * @throws SagaStorageFailure when the storage fails, forwarded from the port
     */
    public function cancelPending(WorkflowId $id, ?string $effectGroup = null): int
    {
        return $this->writer->cancelPending($id, $effectGroup);
    }
}
