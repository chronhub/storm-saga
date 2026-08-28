<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\Activity;
use ValueError;

/**
 * Declares one state of the workflow: the `key`, the `type` of activity, wait, final, or schedule, the
 * `activity` FQCN for activity states, and an optional `timeoutSeconds` that arms a per-state timer whose
 * firing yields the `Timeout` trigger; the target is a separate `#[On(trigger: 'timeout')]`.
 *
 * Grammar: this attribute declares a state, so it names its own key with `key:`. Every attribute that
 * references a state says `state:`, as in `Start`, `WaitFor`, `Compensate`, `Retry`, and `CircuitBreaker`,
 * or a directional pair such as `On(from:, to:)`. One key is declared here and many `state:` point at it;
 * the build verifies through `DefinitionValidator` that every reference targets a declared key.
 *
 * @see Activity
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class State
{
    public StateType $type;

    /**
     * @param  string|StateType  $type  'activity' | 'wait' | 'final' | 'schedule'
     * @param  class-string|null  $activity  the Activity FQCN for activity states
     *
     * @throws ValueError when `$type` is a string that is not a valid StateType
     */
    public function __construct(
        public string $key,
        string|StateType $type,
        public ?string $activity = null,
        public ?int $timeoutSeconds = null,
    ) {
        $this->type = is_string($type) ? StateType::from($type) : $type;
    }
}
