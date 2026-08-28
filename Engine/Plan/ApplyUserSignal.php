<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * Apply an application-level signal to the live instance: invoke the workflow's declared handler for
 * the signal object's class, persist the returned vars at the same state with no transition, the
 * event/signal split, and write its commands to the outbox, atomically with the version bump.
 */
final readonly class ApplyUserSignal implements StepPlan
{
    public function __construct(
        public object $signal,
    ) {}
}
