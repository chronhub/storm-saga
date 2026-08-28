<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Message\HasConstructablePayload;

/**
 * The spawn command a parent workflow issues through its ordinary command outbox: durable intent,
 * with the outbox's provenance, retry, dead-letter and dedup for free. The framework handler mints
 * the child identity, guards the spawn, and starts the child; the parent never names the child's
 * correlation, it names a slot.
 *
 * The parent identity rides the payload, but the HANDLER trusts the parent ROW, not the command:
 * root and depth are derived from the row the alive-guard fetches anyway, so a stale or forged
 * command cannot inflate a lineage.
 *
 * Spawn vars must be functions of the parent's state, with no clock and no randomness, the same
 * determinism family as activities: a redelivered spawn re-mints the same identity, and the FIRST
 * birth's vars win.
 */
final class StartChildWorkflow implements SerializablePayload
{
    use HasConstructablePayload;

    public string $parentWorkflowType { get => (string) $this->payload['parent_workflow_type']; }

    public string $parentCorrelationId { get => (string) $this->payload['parent_correlation_id']; }

    public string $childWorkflowType { get => (string) $this->payload['child_workflow_type']; }

    public string $slot { get => (string) $this->payload['slot']; }

    /** @var array<string, mixed> */
    public array $vars { get => (array) $this->payload['vars']; }

    /**
     * @param  array<string, mixed>  $vars
     */
    public static function with(string $parentWorkflowType, string $parentCorrelationId, string $childWorkflowType, string $slot, array $vars = []): self
    {
        return new self([
            'parent_workflow_type' => $parentWorkflowType,
            'parent_correlation_id' => $parentCorrelationId,
            'child_workflow_type' => $childWorkflowType,
            'slot' => $slot,
            'vars' => $vars,
        ]);
    }
}
