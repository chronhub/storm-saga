<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * Stop this saga on an operator's word: halt where it sits and roll back what is safe to undo, with
 * positional eligibility, since progression proves completion; an unconfirmed in-flight step is
 * skipped and flagged, never blindly compensated. Planned only when the policy allowed the cancel;
 * an unforced cancel at an effect-gating wait is refused.
 *
 * @see SkipReason::InFlightEffect
 */
final readonly class CancelInstance implements StepPlan
{
    public function __construct(
        public ?string $reason = null,
    ) {}
}
