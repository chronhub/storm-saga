<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted when an operator froze ONE instance: `paused_at` stamped, the reason beside it, the
 * status still `running`; a paused saga is a living one that is not executed. Its state timers
 * stay due at their original instants, unclaimed until the resume; facts addressed to it ride the
 * retryable channel. Dispatched by the pause verb, not by a step: there is no step. Telemetry only.
 *
 * @see SagaResumed
 */
final readonly class SagaPaused implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public ?string $reason,
    ) {}
}
