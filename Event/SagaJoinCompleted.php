<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when the LAST arm of a join completed and the saga crossed the joining
 * wait: every arm's effect is kept, each holding its own confirmed entry in the compensation log,
 * so a later rollback unwinds them arm by arm. Telemetry only.
 *
 * @see SagaJoinArmArrived the partial arrivals that preceded it
 */
final readonly class SagaJoinCompleted implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $arms
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public array $arms,
    ) {}
}
