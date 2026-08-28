<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Build\Rules\EffectRules;
use Storm\Saga\Engine\StepPolicy;

/**
 * The effect-gating predicate, owned in one place so the build-time guard
 * `EffectRules::engineOwnsEffectGatingDeadlines()` and the runtime routing `StepPolicy::gatingWait()`
 * can never drift apart.
 *
 * A state is effect-gating when it is the `success`-target of some activity, an "issue-and-wait" point:
 * the activity issued a command and this state gates on its outcome, so the effect is in flight, with
 * committed-but-unconfirmed its normal transient state in a fully async pipeline. A deadline there must
 * escalate, never finalize, since finalizing would discard a possibly committed effect or duplicate it on
 * a later rollback.
 *
 * The caller owns any precondition, such as the state being a `WaitState`; this answers only the
 * success-target question over the definition's states.
 *
 * @see StepPolicy::gatingWait()
 * @see EffectRules::engineOwnsEffectGatingDeadlines()
 */
final class EffectGating
{
    /**
     * Whether `$key` is the `success`-target of some `ActivityState` in `$states`.
     *
     * @param  array<string, State>  $states
     */
    public static function gates(array $states, string $key): bool
    {
        foreach ($states as $source) {
            if (! $source instanceof ActivityState) {
                continue;
            }

            if (array_any($source->transitions, fn ($transition) => $transition->trigger === OnTrigger::Success && $transition->to === $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$key` is the `success`-target of the specific activity `$activityKey`, the settle's pairing
     * predicate: a dead-lettered command may only settle the wait its own issuing step gates. `gates()`
     * answers gated by anyone; this answers gated by that one.
     *
     * @param  array<string, State>  $states
     */
    public static function gatedBy(array $states, string $key, string $activityKey): bool
    {
        $source = $states[$activityKey] ?? null;
        if (! $source instanceof ActivityState) {
            return false;
        }

        return array_any($source->transitions, fn ($transition) => $transition->trigger === OnTrigger::Success && $transition->to === $key);
    }
}
