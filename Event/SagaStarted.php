<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after the step's transaction commits, when a saga instance is first created at its start
 * state. A telemetry and observation seam: a plain object dispatched through a PSR event dispatcher;
 * it carries no behavior and the engine works whether anything listens.
 */
final readonly class SagaStarted implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $startState,
    ) {}
}
