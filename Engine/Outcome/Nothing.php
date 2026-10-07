<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

use Storm\Saga\Engine\Plan\SkipReason;

/**
 * The step did nothing. A reason distinguishes a policy skip or an absorbed join outcome
 * from an unconsumed advance, where `$reason === null` lets the executor judge whether delivery
 * can still succeed later. The public bool remains false; a refused cancel is announced as
 * `SagaCancelRefused`.
 */
final readonly class Nothing implements StepOutcome
{
    public function __construct(
        public ?SkipReason $reason = null,
    ) {}
}
