<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

use Storm\Saga\Engine\Stimulus;

/**
 * Run the machine from the loaded row, the gate into the drive loop. The Stimulus says what the
 * first hop reacts to: a delivered event, a fired timeout, or nothing to re-run the current state.
 */
final readonly class AdvanceInstance implements StepPlan
{
    public function __construct(
        public Stimulus $stimulus,
    ) {}
}
