<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Message\HasConstructablePayload;

/**
 * The cascade command a settling parent writes to its outbox, one per living child: durable
 * intent, at-least-once, surviving the settle's recall by the same ordering the compensation
 * commands rely on. The cascade is GUARANTEED, never immediate: a child may advance between the
 * parent's settle and this command landing, and both sides are idempotent about it.
 *
 * `$force` is the parent's own, inherited as-is: an operator who forces the ROOT wants the tree
 * dead; a nominal cascade carries false and every child compensates properly.
 */
final class CancelChildWorkflow implements SerializablePayload
{
    use HasConstructablePayload;

    public string $parentWorkflowType { get => (string) $this->payload['parent_workflow_type']; }

    public string $parentCorrelationId { get => (string) $this->payload['parent_correlation_id']; }

    public string $childWorkflowType { get => (string) $this->payload['child_workflow_type']; }

    public string $childCorrelationId { get => (string) $this->payload['child_correlation_id']; }

    public ?string $reason { get => isset($this->payload['reason']) ? (string) $this->payload['reason'] : null; }

    public bool $force { get => (bool) $this->payload['force']; }

    public static function with(string $parentWorkflowType, string $parentCorrelationId, string $childWorkflowType, string $childCorrelationId, ?string $reason, bool $force): self
    {
        return new self([
            'parent_workflow_type' => $parentWorkflowType,
            'parent_correlation_id' => $parentCorrelationId,
            'child_workflow_type' => $childWorkflowType,
            'child_correlation_id' => $childCorrelationId,
            'reason' => $reason,
            'force' => $force,
        ]);
    }
}
