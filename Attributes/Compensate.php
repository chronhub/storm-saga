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
 * step is always eligible at a positional halt, where progression already implies confirmation, but is
 * treated as unverifiable and skipped at the global deadline.
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
