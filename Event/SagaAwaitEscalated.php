<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a wait that confirms an in-flight `confirmedBy` effect hits its deadline
 * while still unconfirmed. The deadline is liveness, not a decision: the engine re-arms the timer and
 * emits this instead of finalizing, because in a fully async pipeline "not yet confirmed" is the normal
 * transient state of an in-flight effect; finalizing would discard a possibly committed effect. Operators
 * watch this to spot a confirmation genuinely lagging rather than merely slow. Telemetry only.
 */
final readonly class SagaAwaitEscalated implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
    ) {}
}
