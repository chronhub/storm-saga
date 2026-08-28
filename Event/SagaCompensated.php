<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a saga rolled back, whether from a terminal step failure, an operator
 * cancel, or a global-deadline rollback of confirmed steps, and the engine ran the eligible
 * compensations in reverse, ending the saga as `compensated`. `$states` are the state keys whose
 * compensation was run, in reverse order. Telemetry only. A compensation that itself failed surfaces
 * as a `CompensationFailed`.
 *
 * @see SagaStarted
 * @see CompensationFailed
 */
final readonly class SagaCompensated implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $states
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public array $states,
    ) {}
}
