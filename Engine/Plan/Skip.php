<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * Do nothing, and say why. The reason makes otherwise-indistinguishable no-ops diagnosable, whether
 * not-found, settled, or stale, without adding any new channel: the engine still returns false.
 */
final readonly class Skip implements StepPlan
{
    public function __construct(
        public SkipReason $reason,
    ) {}
}
