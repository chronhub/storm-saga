<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, for each state change a step makes; a saga that runs several transitions in
 * one drive emits one of these per hop. Telemetry only.
 *
 * @see SagaStarted
 */
final readonly class SagaTransitioned implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $from,
        public string $to,
    ) {}
}
