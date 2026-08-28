<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * A child reference on its parent's snapshot, enough to walk the tree: who, where, and whether it
 * still lives, without inspecting each child. Display-shaped; the full picture of a child is its
 * own snapshot, one `inspect()` away.
 *
 * @see SagaSnapshot
 */
final readonly class ChildSnapshot
{
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $status,
    ) {}

    /**
     * @return array{workflow_type: string, correlation_id: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'workflow_type' => $this->workflowType,
            'correlation_id' => $this->correlationId,
            'status' => $this->status,
        ];
    }
}
