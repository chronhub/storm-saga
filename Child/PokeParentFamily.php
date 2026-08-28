<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Message\HasConstructablePayload;
use Storm\Saga\Attributes\Spawns;

/**
 * The command a member of an indexed spawn family writes to its outbox as it settles terminally,
 * addressed to its parent: durable intent, at-least-once, written in the member's own step so it
 * cannot exist without the settle that earns it.
 *
 * What it asks for is a re-read, never an action. The parent's gate absorbs a conclusion that lands
 * while the family is incomplete, and the event is consumed by that rest; when the absorbed
 * conclusion turns out to be the LAST to arrive, nothing capable of crossing the wait exists any
 * more. This command is the only thing that then happens in the system, so it is what gives the
 * parent its chance to spend the crossing it parked. The parent re-judges completeness from the
 * database and does nothing when it owes nothing, which is the common answer: every member pokes,
 * and only the settle that completes the family can find work.
 *
 * It carries no family name on purpose. A parent's wait may await several families, and the gate
 * judges them together, so naming one would invite a caller to believe a poke is scoped to it.
 *
 * @see Spawns
 */
final class PokeParentFamily implements SerializablePayload
{
    use HasConstructablePayload;

    public string $parentWorkflowType { get => (string) $this->payload['parent_workflow_type']; }

    public string $parentCorrelationId { get => (string) $this->payload['parent_correlation_id']; }

    public string $childWorkflowType { get => (string) $this->payload['child_workflow_type']; }

    public string $childCorrelationId { get => (string) $this->payload['child_correlation_id']; }

    public static function with(string $parentWorkflowType, string $parentCorrelationId, string $childWorkflowType, string $childCorrelationId): self
    {
        return new self([
            'parent_workflow_type' => $parentWorkflowType,
            'parent_correlation_id' => $parentCorrelationId,
            'child_workflow_type' => $childWorkflowType,
            'child_correlation_id' => $childCorrelationId,
        ]);
    }
}
