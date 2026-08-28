<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use ReflectionException;
use Storm\Saga\Build\Rules\EffectRules;
use Storm\Saga\Build\Rules\ReachabilityRules;
use Storm\Saga\Build\Rules\StructuralRules;
use Storm\Saga\Build\Rules\TimeRules;
use Storm\Saga\Build\Rules\TopologyRules;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\State;

/**
 * The workflow's structural rules, judged in two passes over five coherent families: structural,
 * time, effect, topology, and reachability. Each family's method list is its catalog, one rule per
 * method named by what it rejects, so a new workflow capability adds its coherence rules in one
 * predictable family; this validator owns only the ORDER the rules run in, which is the order the
 * refusals surface in.
 *
 * - `checkDeclaration` holds reference and coherence rules on the declared key set, before any state is
 *   constructed: no duplicate keys, decorators on activities only, transitions between known states,
 *   start and onGlobalTimeout exist, the timeout target has a deadline, and so on.
 *
 * - `checkAssembled` holds shape rules that need the finished state map: the engine-owned timeout guard on
 *   effect-gating waits, per-state timeout against the global deadline, the timeout target reachable
 *   through a non-gating resting point.
 *
 * Constructive failures such as an unresolvable activity, an unknown bound method, or a malformed extract
 * map are not here: they belong to the construction step that holds their context. The families judge the
 * table, the builder builds the pieces.
 *
 * @see InvalidWorkflowDefinition
 */
final readonly class DefinitionValidator
{
    private StructuralRules $structural;

    private TimeRules $time;

    private EffectRules $effects;

    private TopologyRules $topology;

    private ReachabilityRules $reachability;

    public function __construct()
    {
        $this->structural = new StructuralRules;
        $this->time = new TimeRules;
        $this->effects = new EffectRules;
        $this->topology = new TopologyRules;
        $this->reachability = new ReachabilityRules;
    }

    /**
     * The declaration pass: every rule that can be judged on the declared key set, before any state object
     * exists. The parts arrive as one {@see Declaration} rather than ten positional parameters, so a new
     * declarative feature names itself instead of widening this call.
     *
     * @throws InvalidWorkflowDefinition when the declaration is structurally incoherent
     * @throws ReflectionException when a class is not resolvable
     */
    public function checkDeclaration(Declaration $it): void
    {
        $workflow = $it->workflow;

        $this->structural->someStateIsDeclared($workflow, $it->declared);
        $this->structural->everyStateKeyIsNamed($workflow, $it->declared);
        $types = $this->structural->everyStateKeyIsUnique($workflow, $it->declared);
        $this->structural->decoratorsTargetActivityStates($workflow, $it->decoratorsByLabel, $types);
        $this->structural->waitForTargetsWaitStates($workflow, array_keys($it->waits), $types);
        $this->structural->transitionsConnectKnownStates($workflow, $it->transitions, $types);
        $this->structural->unguardedTransitionsShadowNoSibling($workflow, $it->transitions);
        $this->structural->onEventRidesAnEventTrigger($workflow, $it->transitions);
        $this->structural->onEventClassesExist($workflow, $it->transitions);
        $this->structural->startStateExists($workflow, $it->start, $types);
        $this->time->globalTimeoutTargetExists($workflow, $it->onGlobalTimeout, $types);
        $this->time->globalTimeoutTargetHasADeadline($workflow, $it->onGlobalTimeout, $it->globalTimeout);
        $this->time->waitDeadlineFieldsAreCoherent($workflow, $it->waits);
        $this->time->onDeadlineTargetsAKnownState($workflow, $it->waits, $types);
        $this->time->scheduleForTargetsScheduleStates($workflow, array_keys($it->schedules), $types);
        $this->time->scheduleWorkflowHasNoGlobalDeadline($workflow, $types, $it->globalTimeout);
        $this->time->scheduleCadenceFieldsAreCoherent($workflow, $it->schedules);
        $this->structural->waitEventsAreWellFormed($workflow, $it->waits);
        $this->reachability->waitsRouteTheirEvents($workflow, $it->waits, $it->transitions);
        $this->structural->onEventTargetsAConcreteClass($workflow, $it->transitions);
        $this->structural->transitionTriggersMatchTheStateKind($workflow, $it->transitions, $types);
        $this->structural->stateFieldsMatchTheKind($workflow, $it->declared);
        $this->time->durationsArePositive($workflow, $it->declared, $it->waits, $it->globalTimeout, $it->schedules);
        $this->time->decoratorValuesAreCoherent($workflow, $it->decoratorsByLabel);
        $this->structural->signalClassesAreConcrete($workflow, $it->signals);
        $this->topology->spawnDeclarationsAreCoherent($workflow, $it->spawns, $it->declared);
        $this->topology->spawningWorkflowIsNotReusable($workflow, $it->spawns, $it->reuse);
        $this->time->retimablesTargetWaitStates($workflow, array_keys($it->retimables), $types);
        $this->time->retimableCapsAreCoherent($workflow, $it->retimables);
        $this->effects->raceArmsAreCoherent($workflow, $it->raceArms, $types, $it->decoratorsByLabel['Compensate'] ?? []);
        $this->effects->joinArmsAreCoherent($workflow, $it->joinArms, $types, $it->decoratorsByLabel['Compensate'] ?? [], $it->raceArms);
        $this->effects->armedStatesKeepOneSuccessEdge($workflow, $it->raceArms, $it->joinArms, $it->transitions);
    }

    /**
     * The assembled pass: the shape rules that need the finished map, since gating is a property of the
     * whole graph and timeouts live on the built states.
     *
     * @param  array<string, State>  $states
     * @param  list<string>  $awaitedBySpawn  the wait keys a `#[Spawns]` names, read by the liveness rule
     *
     * @throws InvalidWorkflowDefinition when the assembled table breaks a shape rule
     */
    public function checkAssembled(string $workflow, array $states, array $awaitedBySpawn, ?int $globalTimeout, ?string $onGlobalTimeout): void
    {
        $this->effects->engineOwnsEffectGatingDeadlines($workflow, $states);
        $this->time->perStateTimeoutsStayBelowGlobal($workflow, $states, $globalTimeout);
        $this->time->retryElapsedBudgetsStayBelowGlobal($workflow, $states, $globalTimeout);
        $this->reachability->globalTimeoutTargetIsReachable($workflow, $states, $onGlobalTimeout);
        $this->time->heartbeatWaitsMustGate($workflow, $states);
        $this->time->retriableRequiresHeartbeat($workflow, $states);
        $this->time->scheduleStatesHaveAScheduleEdge($workflow, $states);
        $this->reachability->everyWaitDeclaresLiveness($workflow, $states, $awaitedBySpawn, $globalTimeout);
        $this->effects->compensableGatingStepsDeclareConfirmedBy($workflow, $states);
        $this->reachability->compensatableStatesAvoidCycles($workflow, $states);
        $this->reachability->scheduleEdgesKeepAnUnguardedPath($workflow, $states);
        $this->reachability->waitEventsKeepAnUnguardedRoute($workflow, $states);
        $this->time->retimedWaitsCarryTheirOwnDeadline($workflow, $states);
        $this->effects->racesSettleOnTheirOwnOutcomes($workflow, $states);
        $this->effects->joinsCompleteOnTheirOwnOutcomes($workflow, $states);
    }
}
