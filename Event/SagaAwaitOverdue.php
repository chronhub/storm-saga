<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a retriable effect-gating wait reaches the instance-wide global cap with its
 * outcome still unconfirmed. Unlike {@see SagaAwaitEscalated}, the recurring liveness heartbeat within the
 * cap, this fires ONCE at the cap: the engine waives the cap so it never finalizes an in-flight effect per
 * inv. 2, disarms BOTH the spent global timer and the wait's heartbeat, and hands the saga off. The saga
 * goes quiet with no more re-arming for nothing and stays parked, non-terminal, until its outcome is
 * resolved AT REST when reconciliation delivers the real event, or the upstream effect is reclaimed at its
 * TTL. A reconciler or operator watches this to pick up a leg that is genuinely overdue, its confirmation
 * lost, not merely lagging. Telemetry and reconciliation trigger; never a finalize.
 */
final readonly class SagaAwaitOverdue implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
    ) {}
}
