<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

use Storm\Saga\Engine\EffectEvidence;

/**
 * One command a saga issued, read from the outbox for introspection: its dispatch state, the message type,
 * the bus, the attempt count and the last error if it dead-lettered.
 *
 * Plus its PROVENANCE, which is what turns a list of commands into an account of who issued what:
 * `issuedFromState` names the step whose run emitted it and `issuedAtVersion` the step marker,
 * distinct across a cycle's re-visits, while `generation` says which run of the correlation it belongs
 * to. Together they answer the question an operator holding a dead-lettered command actually asks.
 *
 * And the two fields a REPAIR needs. `messageId` names the row; `storm:saga:redrive` takes it, and
 * this snapshot is the only place to read it. `evidence` says whether re-driving is safe at all:
 * `uncommitted` means the effect provably never landed, `unknown` means nobody proved anything and a
 * replay may double it. An inspection that shows a dead-lettered command without saying which of the
 * two it is leaves the operator to guess the one thing that decides.
 *
 * @see SagaInspectionGateway
 */
final readonly class OutboxSnapshot
{
    public function __construct(
        public string $status,
        public ?string $command,
        public string $bus,
        public int $attempts,
        public string $createdAt,
        public ?string $lastError,
        public string $issuedFromState,
        public int $issuedAtVersion,
        public int $generation,
        public ?string $messageId = null,
        public EffectEvidence $evidence = EffectEvidence::Unknown,
        public ?string $effectGroup = null,
    ) {}

    /**
     * The machine-readable shape, snake_case wire keys: one contract for every renderer, so the
     * console `--json` and the ops HTTP surface serve THIS and scripts read one format.
     *
     * @return array{status: string, command: string|null, bus: string, attempts: int, created_at: string, last_error: string|null, issued_from_state: string, issued_at_version: int, generation: int, message_id: string|null, evidence: string, effect_group: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'command' => $this->command,
            'bus' => $this->bus,
            'attempts' => $this->attempts,
            'created_at' => $this->createdAt,
            'last_error' => $this->lastError,
            'issued_from_state' => $this->issuedFromState,
            'issued_at_version' => $this->issuedAtVersion,
            'generation' => $this->generation,
            'message_id' => $this->messageId,
            'evidence' => $this->evidence->value,
            'effect_group' => $this->effectGroup,
        ];
    }
}
