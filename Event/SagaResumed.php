<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted when an operator lifted ONE instance's freeze: the stamp cleared, the saga executable
 * again. Deadlines that expired during the window fire at the next timer cycle, at their ORIGINAL
 * instants: the pause never manufactured time the business did not grant. Dispatched by the resume
 * verb. Telemetry only.
 *
 * @see SagaPaused
 */
final readonly class SagaResumed implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
    ) {}
}
