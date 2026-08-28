<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\JoinPolicy;

/**
 * One arm of a declared JOIN: `$state`, an activity, atomically issues one command per arm, and the
 * saga only leaves the joining wait when EVERY arm's completion has arrived; this is the race's
 * dual, out at the last outcome instead of the first. Arrivals are marked per arm in the engine's
 * `arms` bag, inside the step's own write, so a redelivered outcome marks nothing twice.
 *
 * `$failedBy`, optional, names the event that reports this arm's DEFINITIVE failure. Its arrival
 * disposes of the whole join in the same step as the verdict: every arm already completed is
 * COMPENSATED through ITS `$compensate`, every command still pending in the outbox is RECALLED by
 * its effect group, and the saga takes the issuing state's failure edge. An arm without `$failedBy`
 * has silence as its only failure mode, which the joining wait's heartbeat escalates. Transient
 * downstream failures are not the join's business: each arm's command is its own message, so the
 * transport's retry already retries per arm.
 *
 * Repeatable: two or more `#[JoinArm]` on one state form the join. The joining wait is DERIVED,
 * never declared: it is the state's `success` target, and the build proves it is a wait whose
 * accepted events are exactly the arms' `$completedBy` and `$failedBy` classes; a join that could
 * exit through an event no arm owns would leave arrivals unaccounted. A join state may not also
 * declare `#[Compensate]`: its compensation is per-arm by construction. Completed arms join the
 * compensation log CONFIRMED as they arrive, so a later rollback undoes each kept effect through
 * its own arm's `$compensate`.
 *
 * The honesty clause every arm signs, inherited from the race: `$compensate` may be dispatched
 * while the arm's forward command is still in flight, since a recall that lost to the relay leaves
 * both traveling, so the undo can reach its handler BEFORE the do. An arm compensation must
 * therefore tolerate undoing what does not exist yet, the same reconciliation shape at-least-once
 * delivery already demands.
 *
 * @see JoinPolicy
 * @see RaceArm the first-outcome dual
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class JoinArm
{
    /**
     * @param  string  $state  the activity whose commands fan the join out
     * @param  string  $arm  this arm's name, the outbox `effect_group` and the log's arm identity
     * @param  string  $command  the command class this arm issues; the stamping key, unique per join
     * @param  string  $completedBy  the outcome event class whose arrival completes this arm
     * @param  string  $compensate  the Activity class that undoes this arm's effect
     * @param  string|null  $failedBy  the event class reporting this arm's definitive failure; null leaves silence to the wait's heartbeat
     */
    public function __construct(
        public string $state,
        public string $arm,
        public string $command,
        public string $completedBy,
        public string $compensate,
        public ?string $failedBy = null,
    ) {}
}
