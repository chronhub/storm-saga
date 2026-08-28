<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when a signal asked for a retime the engine refused, while the signal's
 * vars and commands still landed. A silent drop would lie twice: the caller believes the deadline
 * moved, and the incident later reads an expiry nobody expected; the denial is announced with its
 * reason instead. Reasons: `not_retimable_here`, the resting state carries no `#[Retimable]` grant;
 * `budget_exhausted`, the instance spent its `maxRetimes`; `beyond_extension_cap`, the single move
 * asked for more than `maxExtensionSeconds`. Telemetry only.
 *
 * @see SagaRetimed
 */
final readonly class SagaRetimeDenied implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public string $reason,
    ) {}
}
