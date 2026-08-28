<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\RacePolicy;

/**
 * One arm of a declared RACE: `$state`, an activity, atomically issues one command per arm, its
 * success-target wait accepts whichever outcome arrives first, and the arms whose outcome did NOT
 * win are actively disposed of in the same step as the victory; a still-pending command is
 * RECALLED before the relay ever claims it, and one already in flight is COMPENSATED immediately
 * through `$compensate`, its undo riding the same outbox as forward work.
 *
 * Repeatable: two or more `#[RaceArm]` on one state form the race. The settling wait is DERIVED,
 * never declared: it is the state's `success` target, and the build proves it is a wait whose
 * accepted events are exactly the arms' `$wonBy` classes; a race that could exit through an event
 * no arm owns would leave losers undisposed. A race state may not also declare `#[Compensate]`:
 * its compensation is per-arm by construction, and the winner's arm entry joins the log confirmed
 * at the victory, so a later rollback undoes the kept effect through ITS arm's `$compensate`.
 *
 * The honesty clause every arm signs: `$compensate` may be dispatched while the arm's forward
 * command is still in flight, since a recall that lost the race to the relay leaves both
 * traveling, so the undo can reach its handler BEFORE the do. An arm compensation must therefore
 * tolerate undoing what does not exist yet, the same reconciliation shape at-least-once delivery
 * already demands.
 *
 * @see RacePolicy
 * @see JoinArm the every-arm dual
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class RaceArm
{
    /**
     * @param  string  $state  the activity whose commands fan the race out
     * @param  string  $arm  this arm's name, the outbox `effect_group` and the log's arm identity
     * @param  string  $command  the command class this arm issues; the stamping key, unique per race
     * @param  string  $wonBy  the outcome event class whose arrival makes this arm the winner
     * @param  string  $compensate  the Activity class that undoes this arm's effect
     */
    public function __construct(
        public string $state,
        public string $arm,
        public string $command,
        public string $wonBy,
        public string $compensate,
    ) {}
}
