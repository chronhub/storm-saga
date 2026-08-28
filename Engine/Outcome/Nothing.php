<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

use Storm\Saga\Engine\Plan\SkipReason;

/**
 * The step did nothing: the policy skipped, with its reason, or the machine ran and matched
 * nothing, where `$reason === null` marks an unmoved advance. The engine reports a plain false
 * either way; the reason exists for tests, with one exception the executor gives a voice: a
 * refused cancel is announced as `SagaCancelRefused`.
 */
final readonly class Nothing implements StepOutcome
{
    public function __construct(
        public ?SkipReason $reason = null,
    ) {}
}
