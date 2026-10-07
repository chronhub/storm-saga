<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Declares the compensating activity for an activity `$state`, the action that undoes it. When the saga
 * rolls back after a later step fails terminally, the engine runs the compensations of the
 * already-completed steps in reverse order. `$activity` is a class id resolved from the activity locator,
 * like a state's main activity. Repeatable; one per compensatable state.
 *
 * `$confirmedBy` names the success event that confirms this step's effect actually happened. The forward
 * run logs the step as intent; when the saga later consumes an event of that class, the entry flips to
 * confirmed. A rollback then only undoes confirmed steps when it fires at a location-agnostic point such
 * as the global deadline, where intent may not match confirmed effect and undoing an unconfirmed step
 * could reverse something that never happened. Leave it `null` by default for the simple behavior: the
 * step is eligible at a positional halt, the saga having moved past it, but is treated as unverifiable
 * and skipped at the global deadline. This untracked form is allowed only when the activity emits
 * no commands. Command emissions require `$confirmedBy` and an immediate success wait.
 *
 * Moving past a step proves it ran, never that its commands left. Before any undo, a rollback reads the
 * forward commands of every unconfirmed step: one that issued at least one, none ever claimed by a
 * relay, is skipped as `recalled: never dispatched`, its undo never run and its commands recalled. A
 * step that issued no command keeps the rules above, and a confirmed one is never requalified.
 *
 * The undo tolerates arriving before the do. A rollback that undoes a step leaves the step's commands
 * still pending on their way, so the undo always pairs with the do it reverses, and the relay, which
 * keeps no order between them, may deliver the undo first.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Compensate
{
    /**
     * @param  class-string|null  $confirmedBy  the success event whose delivery confirms this step's effect
     */
    public function __construct(
        public string $state,
        public string $activity,
        public ?string $confirmedBy = null,
    ) {}
}
