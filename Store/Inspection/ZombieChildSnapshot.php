<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * A living child whose parent can no longer own it: the parent settled, or its row is gone. The
 * zombie sweep's row: the cascade is the authority that should have reached it, this view is the
 * BACKSTOP that makes a missed cascade visible instead of silent. `$parentStatus` is null when the
 * parent row no longer exists, the harder orphan.
 *
 * @see SagaInspectionGateway::zombieChildren()
 */
final readonly class ZombieChildSnapshot
{
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $parentCorrelationId,
        public ?string $parentStatus,
        public ?string $startedAt,
    ) {}

    /**
     * @return array{workflow_type: string, correlation_id: string, parent_correlation_id: string, parent_status: ?string, started_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'workflow_type' => $this->workflowType,
            'correlation_id' => $this->correlationId,
            'parent_correlation_id' => $this->parentCorrelationId,
            'parent_status' => $this->parentStatus,
            'started_at' => $this->startedAt,
        ];
    }
}
