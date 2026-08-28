<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when a signal moved the resting wait's deadline: the new deadline sits
 * `seconds` from the step's instant, and `retimes` is the instance's lifetime count of applied
 * retimes, the durable budget `#[Retimable(maxRetimes:)]` caps. The state does NOT change; like a
 * retry, this is the only history trace of the move. Telemetry only.
 *
 * @see SagaRetimeDenied
 */
final readonly class SagaRetimed implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public int $seconds,
        public int $retimes,
    ) {}
}
