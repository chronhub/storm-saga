<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Transition;

/**
 * Picks the target state for a fired trigger: the first transition on `$state` whose trigger matches
 * and whose guard, if any, passes for the current `vars`. Returns null when none match; the caller,
 * a state runner, turns that into a `Verdict\Halt`.
 *
 * For the `event` trigger, a transition may be scoped to a specific event class via `onEvent`: an
 * exact `onEvent === $eventClass` match is tried first, then a catch-all `onEvent === null`. So a
 * single wait routes to different targets by which event arrived, and a specific edge always wins
 * over the catch-all regardless of declaration order. Non-event triggers have no `onEvent` and match
 * in declaration order.
 *
 * @see Halt
 */
final class TransitionSelector
{
    /**
     * @param  array<string, mixed>  $vars  passed to each transition's guard
     * @param  class-string|null  $eventClass  the class of the event that fired, event trigger only
     */
    public function select(State $state, OnTrigger $trigger, array $vars, ?string $eventClass = null): ?string
    {
        // a transition scoped to exactly this event class wins over a catch-all
        foreach ($state->transitions as $transition) {
            if ($transition->trigger === $trigger && $transition->onEvent !== null
                && $transition->onEvent === $eventClass && $this->passes($transition, $vars)) {
                return $transition->to;
            }
        }

        // then the catch-all with no onEvent; it covers every non-event trigger too
        foreach ($state->transitions as $transition) {
            if ($transition->trigger === $trigger && $transition->onEvent === null && $this->passes($transition, $vars)) {
                return $transition->to;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function passes(Transition $transition, array $vars): bool
    {
        return $transition->guard === null || ($transition->guard)($vars);
    }
}
