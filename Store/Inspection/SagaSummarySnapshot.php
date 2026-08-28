<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * One saga instance as a listing row: the scalars of its `workflow_instances` row and nothing else.
 *
 * Deliberately NOT a lighter {@see SagaSnapshot}: that one answers "what happened to THIS saga" and
 * pays 1+2N reads for its timers, outbox and children; this one answers "which sagas are there" and
 * is one row of one query. A listing that carried satellites would turn a filtered scan into an N+1,
 * so the two shapes stay separate and the listing points at `storm:saga:inspect` for the rest.
 *
 * `parentCorrelationId` is the one lineage scalar kept, because it rides the same row at no cost and
 * answers the question a listing raises immediately: is this thing a child of something else.
 *
 * @see SagaInspectionGateway::list() the producer
 */
final readonly class SagaSummarySnapshot
{
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $stateKey,
        public string $status,
        public int $version,
        public int $generation,
        public int $definitionVersion,
        public int $retryTotal,
        public ?string $startedAt,
        public ?string $updatedAt,
        public ?string $waivedAt,
        public ?string $parentCorrelationId,
        /** The operator freeze stamp, null when executable; a paused saga must SAY so in a listing. */
        public ?string $pausedAt = null,
        /**
         * Whether the whole workflow TYPE is frozen, a fact no instance carries: the type freeze lives
         * in `workflow_pauses` alone and gates births as well as steps, so it is the widest freeze of
         * the surface and the one an instance stamp cannot reveal.
         */
        public bool $typePaused = false,
    ) {}

    /**
     * The machine shape, snake_case on the wire: the same contract the console serves with `--json`
     * and the ops HTTP surface serves as a resource, so a script reads one format wherever it looks.
     *
     * @return array{workflow_type: string, correlation_id: string, state_key: string, status: string, version: int, generation: int, definition_version: int, retry_total: int, started_at: string|null, updated_at: string|null, waived_at: string|null, parent_correlation_id: string|null, paused_at: string|null, type_paused: bool}
     */
    public function toArray(): array
    {
        return [
            'workflow_type' => $this->workflowType,
            'correlation_id' => $this->correlationId,
            'state_key' => $this->stateKey,
            'status' => $this->status,
            'version' => $this->version,
            'generation' => $this->generation,
            'definition_version' => $this->definitionVersion,
            'retry_total' => $this->retryTotal,
            'started_at' => $this->startedAt,
            'updated_at' => $this->updatedAt,
            'waived_at' => $this->waivedAt,
            'paused_at' => $this->pausedAt,
            'type_paused' => $this->typePaused,
            'parent_correlation_id' => $this->parentCorrelationId,
        ];
    }
}
