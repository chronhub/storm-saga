<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Workflow\CorrelationReuse;
use Storm\Saga\Workflow\RetimePolicy;

/**
 * What a workflow class DECLARED, gathered once by the builder and judged as one thing by
 * {@see DefinitionValidator::checkDeclaration()}: the raw attributes, before any state object exists.
 *
 * A parameter object rather than a long positional call: a new declarative feature adds a NAMED
 * property, the call site never changes, and a reader sees the declaration's parts at once instead
 * of counting commas.
 *
 * Deliberately dumb: no behavior, no validation. The rules judge, this only carries; a guard here
 * would split the error catalog across two homes, and that catalog's single home is the point of
 * {@see DefinitionValidator}.
 */
final readonly class Declaration
{
    /**
     * @param  string  $workflow  the workflow NAME, the subject every refusal message names
     * @param  list<StateAttribute>  $declared  the raw `#[State]` attributes, duplicates visible
     * @param  list<On>  $transitions  the raw `#[On]` attributes
     * @param  array<string, array<string, mixed>>  $decoratorsByLabel  label-keyed map of stateKey-keyed maps, such as `Retry` and `Compensate`
     * @param  array<string, WaitFor>  $waits  each stateKey to its `#[WaitFor]`, holding the wait's events and deadline fields
     * @param  string|null  $start  the resolved start key, from a declared `#[Start]` or the first state; null when no state is declared
     * @param  string|null  $onGlobalTimeout  the state the global deadline routes to, if declared
     * @param  int|null  $globalTimeout  the workflow-level deadline in seconds, if declared
     * @param  array<string, Schedule>  $schedules  each stateKey to its `#[Schedule]`, holding the schedule's cadence and catch-up fields
     * @param  array<string, Signal>  $signals  each signal class to its `#[Signal]` declaration
     * @param  list<Spawns>  $spawns  the raw `#[Spawns]` attributes, duplicates visible
     * @param  array<string, RetimePolicy>  $retimables  each stateKey to its `#[Retimable]` grant and caps
     * @param  array<string, list<RaceArm>>  $raceArms  each stateKey to its declared `#[RaceArm]`s
     * @param  array<string, list<JoinArm>>  $joinArms  each stateKey to its declared `#[JoinArm]`s
     * @param  CorrelationReuse  $reuse  the declared correlation reuse policy
     */
    public function __construct(
        public string $workflow,
        public array $declared,
        public array $transitions,
        public array $decoratorsByLabel,
        public array $waits,
        public ?string $start,
        public ?string $onGlobalTimeout,
        public ?int $globalTimeout,
        public array $schedules = [],
        public array $signals = [],
        public array $spawns = [],
        public array $retimables = [],
        public array $raceArms = [],
        public array $joinArms = [],
        public CorrelationReuse $reuse = CorrelationReuse::Reject,
    ) {}
}
