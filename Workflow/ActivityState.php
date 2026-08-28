<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Workflow\Fallback\FallbackPolicy;

/**
 * Runs an `Activity`, then transitions on its outcome, `Success` or `Failure`.
 *
 * @see Compensate
 */
final class ActivityState extends State
{
    public function __construct(
        string $key,
        public readonly Activity $activity,
        /** Governs re-attempts before a failure transition. */
        public readonly ?RetryPolicy $retry = null,
        /** Arms a per-state timer. */
        public readonly ?Timeout $timeout = null,
        /** The activity that undoes this step, run in reverse order across the completed steps when the saga rolls back. */
        public readonly ?Activity $compensation = null,
        /** Fast-fails the activity while a shared resource is tripped open. */
        public readonly ?CircuitBreakerPolicy $circuitBreaker = null,
        /**
         * The success event whose delivery confirms this step's effect, so a rollback can tell
         * intent from confirmed effect.
         *
         * @var class-string|null
         */
        public readonly ?string $compensationConfirmedBy = null,
        /** Degrades a failed or circuit-open activity to an alternative result instead of failing. */
        public readonly ?FallbackPolicy $fallback = null,
        array $transitions = [],
        /** The declared race this state fans out, its arms and their per-arm compensations; exclusive with `$compensation`. */
        public readonly ?RacePolicy $race = null,
        /** The declared join this state fans out; exclusive with `$compensation` AND with `$race`, since a step is a race or a join, never both. */
        public readonly ?JoinPolicy $join = null,
    ) {
        parent::__construct($key, $transitions);
    }
}
