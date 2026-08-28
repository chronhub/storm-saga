<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\DeadlineEnforcer;

/**
 * Emitted after commit for the logged compensatable steps that were NOT rolled back. `$states` is
 * that audit trail, the steps left for reconciliation, not the ones that were undone.
 *
 * A step is skipped when undoing it where the saga settled is unsafe:
 *
 * - Its effect is unconfirmed at a location-agnostic global deadline; the log records that a step's
 *   activity ran, not that its effect landed.
 *
 * - It is degraded; a fallback salvaged the success, so the real effect is uncertain.
 *
 * - The global cap bounds a non-retriable effect-gating wait; releasing a possibly committed effect
 *   would create money.
 *
 * The engine still rolls back what IS safe, the steps a success event confirmed via
 * `#[Compensate(confirmedBy:)]`, and flags only the rest here. Emitted from the compensation path
 * and the global-deadline branches.
 *
 * Telemetry only.
 *
 * @see SagaStarted
 * @see SagaGloballyTimedOut
 * @see Compensator
 * @see DeadlineEnforcer
 */
final readonly class SagaCompensationSkipped implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $states  compensatable steps NOT rolled back
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public array $states,
    ) {}
}
