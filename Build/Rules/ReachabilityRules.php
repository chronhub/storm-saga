<?php

declare(strict_types=1);

namespace Storm\Saga\Build\Rules;

use ReflectionClass;
use ReflectionException;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\StepPolicy;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\EffectGating;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WaitState;

/**
 * The reachability family of the definition rules: cycles, liveness, and the unguarded routes
 * a declared edge must keep usable.
 */
final readonly class ReachabilityRules
{
    /**
     * An `onGlobalTimeout` target can only be driven from a non-gating resting point: a state where the
     * saga can sit when the global deadline fires and the policy routes to EnforceGlobalDeadline, which
     * drives the target. At an effect-gating wait the cap can only HaltAtGlobalCap, bounding but never
     * finalizing, so if every resting point gates, the declared target is structurally unreachable dead
     * config, as with `onGlobalTimeout: 'failed'` declared over only-gating waits.
     *
     * A non-gating resting point is a non-gating `WaitState` reached by an event, or an `ActivityState`
     * that can go async because it declares a timeout; a sync activity is transient and never rests. This
     * is a safe over-approximation: existence is enough, with no reachability-from-start needed, since the
     * case that bites has neither and erring toward acceptance never falsely rejects a live workflow.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when onGlobalTimeout is declared but every resting point gates
     *
     * @see StepPolicy
     */
    public function globalTimeoutTargetIsReachable(string $workflow, array $states, ?string $onGlobalTimeout): void
    {
        if ($onGlobalTimeout === null) {
            return; // no forced target, so nothing to reach
        }

        $hasNonGatingRestingPoint = array_any($states, static fn (State $state): bool => match (true) {
            $state instanceof WaitState => ! EffectGating::gates($states, $state->key),
            $state instanceof ActivityState => $state->timeout !== null,
            default => false,
        });

        if ($hasNonGatingRestingPoint) {
            return; // EnforceGlobalDeadline is reachable here, so the target can be driven
        }

        throw InvalidWorkflowDefinition::onGlobalTimeoutUnreachable($onGlobalTimeout, $workflow);
    }

    /**
     * Every wait must carry a liveness signal: a heartbeat to ping, a deadline to expire, or the workflow's
     * global cap above it. With none, a lost message rests the saga forever, running with zero timers and no
     * announcement, invisible to reconciliation, which derives from dead-letters not from silence. This is
     * the wait side of "async requires a timeout": the activity side is runtime-checked as
     * MissingAsyncTimeout, while this one is statically known.
     *
     * An effect-gating wait, the success-target of an activity, answers to the narrower bar of a heartbeat
     * or the cap: the engine owns its timeout edge, so it declares no deadline of its own. Every other wait
     * takes the deadline or the cap, never a heartbeat, which escalates a step nobody awaits.
     *
     * A wait reachable from `onGlobalTimeout` cannot rely on the cap, which recovery has already
     * consumed. It declares its own liveness even when the workflow has a cap. Reachability includes
     * the target itself and every declared transition, including guarded edges and returns to the
     * main graph; guards cannot prove that a recovery path is impossible.
     *
     * One shape is exempt, and it is DERIVED rather than declared: a wait named by `#[Spawns(awaitedBy:)]`
     * awaits a CHILD, whose own horizon concludes it and delivers the awaited event. A second clock in the
     * parent would race the child rather than protect it, so the spawn declaration IS the exemption and
     * there is no opt-out to write. Nothing here verifies the child concludes, the limit of what a build
     * rule can know; the child answers to this same rule.
     *
     * The exemption covers NON-GATING waits alone, and the gating bar is checked first: the child's
     * clocks prove the child concludes, never that its outcome ARRIVES, and an effect-gating wait
     * watches exactly that delivery seam, which only the parent can watch. A gating spawn-awaited
     * wait therefore still declares its liveness; its heartbeat watches the seam, not the child.
     *
     * For an INDEXED family the exemption's promise, that the child carries the horizon, is made
     * whole at runtime rather than assumed: the family's gate absorbs a conclusion arriving while
     * members are still out and parks the crossing it owes back, and each member's terminal settle
     * pokes the parent to spend it. Without that, a conclusion overtaking its own member's settle
     * would leave the parent resting with every member terminal and no clock of its own, which is
     * exactly the state this exemption would then have licensed.
     *
     * @param  array<string, State>  $states
     * @param  list<string>  $awaitedBySpawn  the wait keys a `#[Spawns]` names, whose horizon is the child's
     *
     * @throws InvalidWorkflowDefinition when a wait can neither ping nor expire and no unspent cap covers it
     */
    public function everyWaitDeclaresLiveness(string $workflow, array $states, array $awaitedBySpawn, ?int $globalTimeout, ?string $onGlobalTimeout = null): void
    {
        $recoveryStates = $onGlobalTimeout === null ? [] : $this->reachableFrom($onGlobalTimeout, $states);

        foreach ($states as $state) {
            if (! $state instanceof WaitState || $state->timeout !== null) {
                continue;
            }

            if ($globalTimeout !== null && ! isset($recoveryStates[$state->key])) {
                continue;
            }

            if (EffectGating::gates($states, $state->key)) {
                throw $globalTimeout === null
                    ? InvalidWorkflowDefinition::gatingWaitWithoutLiveness($state->key, $workflow)
                    : InvalidWorkflowDefinition::recoveryGatingWaitWithoutLiveness($state->key, $workflow);
            }

            if (! in_array($state->key, $awaitedBySpawn, true)) {
                throw $globalTimeout === null
                    ? InvalidWorkflowDefinition::waitWithoutLiveness($state->key, $workflow)
                    : InvalidWorkflowDefinition::recoveryWaitWithoutLiveness($state->key, $workflow);
            }
        }
    }

    /**
     * A compensatable activity must not lie on a transition cycle or self-loop. The compensation log keys
     * its records by state key alone, with no per-visit identity, so a second visit of a compensatable
     * state aliases the first: a class-only confirmation confirms the wrong visit, and the rollback's
     * per-key result map collapses distinct visits into one durable status; the resulting audit can
     * disagree with the undo commands actually issued, a money bug. A cycle through the state is the only
     * shape that can revisit it via the graph, since a `#[Retry]` re-run is not a transition edge, so
     * refuse it at build. A compensatable activity on an acyclic path is visited at most once and stays
     * safe. This is the short-term guard until every visit carries its own execution identity.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a compensatable activity can be revisited through a cycle
     *
     * @see Compensator the per-state-key rollback map this protects
     */
    public function compensatableStatesAvoidCycles(string $workflow, array $states): void
    {
        // a race or join state is compensatable BY CONSTRUCTION: its arms log per-arm entries,
        // keyed (state, arm), and a revisit would duplicate those pairs exactly as a cycle
        // duplicates a bare state key
        $cyclic = array_find(
            $states,
            fn (State $state): bool => $state instanceof ActivityState
                && ($state->compensation !== null || $state->race !== null || $state->join !== null)
                && $this->liesOnACycle($state->key, $states),
        );
        if ($cyclic !== null) {
            throw InvalidWorkflowDefinition::compensatableStateInCycle($cyclic->key, $workflow);
        }
    }

    /**
     * @param  array<string, State>  $states
     * @return array<string, true>
     */
    private function reachableFrom(string $origin, array $states): array
    {
        $stack = [$origin];
        $seen = [];

        while ($stack !== []) {
            $key = array_pop($stack);
            if (isset($seen[$key])) {
                continue;
            }
            // @infection-ignore-all; equivalent: the liveness rule, the one reader of this set, asks isset()
            // alone, which is value-agnostic, as in liesOnACycle() below; only the key's presence matters
            $seen[$key] = true;
            array_push($stack, ...$this->successorsOf($key, $states));
        }

        return $seen;
    }

    /**
     * Whether `$origin` lies on a cycle: some non-empty path of transitions leads from it back to itself. A
     * plain iterative DFS over the transition graph, bounded by a visited set, so a cycle that does NOT pass
     * through `$origin` is walked once and abandoned rather than looped.
     *
     * @param  array<string, State>  $states
     */
    private function liesOnACycle(string $origin, array $states): bool
    {
        $stack = $this->successorsOf($origin, $states);
        $seen = [];

        while ($stack !== []) {
            $key = array_pop($stack);
            if ($key === $origin) {
                return true; // a path of length >= 1 returned to the origin
            }
            if (isset($seen[$key])) {
                continue;
            }
            // @infection-ignore-all; equivalent: the visited guard above reads this only via isset(), which
            // is value-agnostic, since true vs. false both register the key as "seen"; only the key's presence matters
            $seen[$key] = true;
            array_push($stack, ...$this->successorsOf($key, $states));
        }

        return false;
    }

    /**
     * The declared states `$key` transitions to. Edges to undeclared states are skipped: the
     * transition-target rule already rejects those in the declaration pass, but an assembled rule must not
     * assume it ran.
     *
     * @param  array<string, State>  $states
     * @return list<string>
     */
    private function successorsOf(string $key, array $states): array
    {
        $state = $states[$key] ?? null;
        if ($state === null) {
            return [];
        }

        $declared = array_filter($state->transitions, static fn ($transition): bool => isset($states[$transition->to]));

        return array_map(static fn ($transition): string => $transition->to, $declared) |> array_values(...);
    }

    /**
     * A schedule state keeps at least one unguarded schedule edge. Guarded schedule edges route a tick
     * conditionally, the first passing guard wins, which is legitimate; but if every edge is guarded, a tick
     * where all guards reject halts the recurring workflow permanently: the fired cadence timer is consumed,
     * the saga settles `halted`, and no future slot ever fires. The unguarded edge is the catch-all that
     * keeps the cadence alive; "skip this slot" belongs inside the tick.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when every schedule edge of a schedule state is guarded
     */
    public function scheduleEdgesKeepAnUnguardedPath(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof ScheduleState) {
                continue;
            }

            $scheduleEdges = array_filter($state->transitions, static fn ($transition): bool => $transition->trigger === OnTrigger::Schedule);
            if ($scheduleEdges !== [] && ! array_any($scheduleEdges, static fn ($transition): bool => $transition->guard === null)) {
                throw InvalidWorkflowDefinition::scheduleEdgesAllGuarded($state->key, $workflow);
            }
        }
    }

    /**
     * Every CONCRETE event class a wait accepts keeps at least one UNGUARDED route: an edge scoped
     * to that exact class, or a catch-all, with no guard. The twin of the schedule rule above, same
     * hazard in the same terms: a matched event whose class no edge covers, or whose every
     * candidate edge is guarded and every guard rejects, HALTS the saga permanently: the event is
     * consumed by the settle, its redelivery lands on a terminal instance, and the halt rolls back
     * what compensation allows. A routing miss is refused at build, not settled at runtime;
     * "reject this content" belongs to the wait's matcher or inside the target state, never to the
     * last edge's guard.
     *
     * Concrete only, deliberately: an accepted INTERFACE or abstract class names a family whose
     * members are not statically enumerable; a wait may route the members it knows by scoped
     * edges, and an unknown member halting is the documented residual of
     * `onEventTargetsAConcreteClass`, the same residual as a delivered SUBCLASS of an accepted
     * class. Skipped entirely when the wait declares type aliases, since the accepted set is not
     * statically known, the same skip as `waitsRouteTheirEvents`.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when an accepted concrete event class keeps no unguarded route
     * @throws ReflectionException when reflection fails to resolve an accepted class
     */
    public function waitEventsKeepAnUnguardedRoute(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof WaitState || $state->eventTypes !== []) {
                continue;
            }

            $eventEdges = array_filter($state->transitions, static fn ($transition): bool => $transition->trigger === OnTrigger::Event);

            $unrouted = array_find(
                $state->eventClasses,
                static fn (string $accepted): bool => class_exists($accepted)
                    && ! new ReflectionClass($accepted)->isAbstract()
                    && ! array_any(
                        $eventEdges,
                        static fn ($transition): bool => $transition->guard === null
                            && ($transition->onEvent === null || $transition->onEvent === $accepted),
                    ),
            );

            if ($unrouted !== null) {
                throw InvalidWorkflowDefinition::waitEventWithoutUnguardedRoute($unrouted, $state->key, $workflow);
            }
        }
    }

    /**
     * A wait's accepted events and its event edges agree. A wait that accepts events needs at least one
     * `#[On(trigger: 'event')]` edge, since a matched event with no edge halts the saga; an eventless wait,
     * a durable sleep, must not declare one, which would be dead config. An `onEvent`-scoped edge must
     * route a class the wait can actually match, equal to, or a subtype of an accepted class. The check is
     * skipped when the wait declares type aliases: an alias resolves at runtime, so the accepted set is not
     * statically known.
     *
     * @param  array<string, WaitFor>  $waits
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition when a wait and its event edges disagree
     */
    public function waitsRouteTheirEvents(string $workflow, array $waits, array $transitions): void
    {
        foreach ($waits as $wait) {
            $eventEdges = array_filter(
                $transitions,
                static fn (On $on): bool => $on->from === $wait->state && $on->trigger === OnTrigger::Event,
            );

            if ($wait->events === []) {
                if ($eventEdges !== []) {
                    throw InvalidWorkflowDefinition::eventEdgeOnEventlessWait($wait->state, $workflow);
                }

                continue;
            }
            if ($eventEdges === []) {
                throw InvalidWorkflowDefinition::waitWithoutEventEdge($wait->state, $workflow);
            }

            $classes = array_filter($wait->events, static fn (string $event): bool => class_exists($event) || interface_exists($event));
            if (count($classes) !== count($wait->events)) {
                continue;
            }

            $unaccepted = array_find(
                array_map(static fn (On $on): ?string => $on->onEvent, $eventEdges),
                static fn (?string $onEvent): bool => $onEvent !== null
                    && ! array_any($classes, static fn (string $accepted): bool => is_a($onEvent, $accepted, true)),
            );
            if ($unaccepted !== null) {
                throw InvalidWorkflowDefinition::onEventNotAccepted($unaccepted, $wait->state, $workflow);
            }
        }
    }
}
