<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when one arm of a join completed while others are still out: the arm's
 * outcome landed its vars, joined the compensation log confirmed, and the saga rested in place on
 * the joining wait. `remaining` names the arms still awaited, so an operator watching a stuck join
 * reads WHO is late, not just that it is. Telemetry only.
 *
 * @see SagaJoinCompleted the last arrival
 * @see SagaJoinFailed a definitive arm failure
 */
final readonly class SagaJoinArmArrived implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $remaining
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public string $arm,
        public array $remaining,
    ) {}
}
