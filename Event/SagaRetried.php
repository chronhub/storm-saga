<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when a state's activity failed transiently and the engine re-armed a retry,
 * within the per-state `maxAttempts` and the workflow's lifetime retry budget. `attempt` is the new
 * per-state attempt count, `retryTotal` the instance's lifetime total. The state does NOT change,
 * since the saga rests in place awaiting the back-off kick, so this is the ONLY history trace of a
 * retry; a Stay otherwise announces nothing. Telemetry only.
 *
 * @see SagaTransitioned
 */
final readonly class SagaRetried implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public int $attempt,
        public int $retryTotal,
    ) {}
}
