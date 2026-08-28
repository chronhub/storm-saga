<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * One armed timer of a saga, read for introspection; display-shaped as the stored strings, not the
 * rich `WorkflowTimerRow`'s typed kind and instant; the consumers are renderers such as console tables
 * and the ops HTTP surface, never the engine.
 *
 * Carries its row `id` because inspection is where a repair starts: a parked timer freezes its saga
 * until someone un-parks it, and `storm:saga:unpark` needs the id this snapshot is the only place to
 * read. An inspection that shows a stuck row but not how to name it stops one step short.
 *
 * @see SagaInspectionGateway
 */
final readonly class TimerSnapshot
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $stateKey,
        public string $fireAt,
        public ?string $claimedAt,
        public int $attempts = 0,
        public ?string $parkedAt = null,
        public ?string $lastError = null,
    ) {}

    /**
     * The machine-readable shape, snake_case wire keys: one contract for every renderer, so the
     * console `--json` and the ops HTTP surface serve THIS and scripts read one format.
     *
     * @return array{id: int, kind: string, state_key: string, fire_at: string, claimed_at: string|null, attempts: int, parked_at: string|null, last_error: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'state_key' => $this->stateKey,
            'fire_at' => $this->fireAt,
            'claimed_at' => $this->claimedAt,
            'attempts' => $this->attempts,
            'parked_at' => $this->parkedAt,
            'last_error' => $this->lastError,
        ];
    }
}
