<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when a saga reaches a final state; its status becomes `completed`.
 * Telemetry only.
 *
 * @see SagaStarted
 */
final readonly class SagaCompleted implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $finalState,
    ) {}
}
