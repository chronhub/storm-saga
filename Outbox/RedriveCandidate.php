<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Exception\InvalidRedriveBatch;
use Storm\Saga\Exception\InvalidWorkflowId;
use Storm\Saga\Store\WorkflowId;

/**
 * One command selected by a batch redrive, named by its outbox primary key.
 *
 * The workflow type and correlation are what the selection read, not a lookup key: the redrive
 * revalidates both against the row, so a stale or mismatched candidate is refused instead of
 * reaching another saga's command.
 */
final readonly class RedriveCandidate
{
    private WorkflowId $saga;

    /**
     * @throws InvalidRedriveBatch when the id is not positive
     * @throws InvalidWorkflowId when the type or the correlation cannot name a saga
     */
    public function __construct(
        public int $id,
        public string $workflowType,
        public string $correlationId,
    ) {
        if ($id < 1) {
            throw InvalidRedriveBatch::nonPositiveId($id);
        }

        $this->saga = new WorkflowId($workflowType, $correlationId);
    }

    /**
     * The saga whose step fence guards this candidate's redrive.
     */
    public function saga(): WorkflowId
    {
        return $this->saga;
    }
}
