<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * Base of the state hierarchy, sealed by package convention to exactly four kinds: `ActivityState`,
 * `WaitState`, `ScheduleState`, and `FinalState`. The builder constructs no other, and the machine
 * dispatches by these subtypes, so a fifth kind must revisit every `instanceof` dispatch, this list
 * included. Holds its key and its outgoing `Transition`s, both readonly: a State is born complete, since
 * the builder validates every reference on the declared key set first, then constructs, so a definition is
 * immutable end to end and nothing can grow an edge after build.
 *
 * @see ActivityState
 * @see WaitState
 * @see ScheduleState
 * @see FinalState
 * @see Transition
 */
abstract class State
{
    /**
     * @param  list<Transition>  $transitions
     */
    public function __construct(
        public readonly string $key,
        public readonly array $transitions = [],
    ) {}
}
