<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * An operator canceled the saga via `storm:saga:cancel` or `Engine::cancel`, announced FIRST, before
 * the compensation events of the rollback it triggered. `$state` is where the saga sat; `$reason`
 * is the operator's word, for the audit trail.
 */
final readonly class SagaCancelled implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $state,
        public ?string $reason = null,
    ) {}
}
