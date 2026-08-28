<?php

declare(strict_types=1);

namespace Storm\Saga\Build\Rules;

use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Engine\StepPolicy;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\EffectGating;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WaitState;

/**
 * The effect family of the definition rules: compensation, race and join arms, and the
 * effect-gating pairings the settlers rely on.
 */
final readonly class EffectRules
{
    /**
     * A state that fans arms out keeps EXACTLY ONE success edge. Both the settling wait of a race and
     * the joining wait of a join are DERIVED from that edge, statically, taking the first one declared
     * and reading no guard; the runtime selector answers instead with the first success edge whose
     * guard PASSES. A second success edge therefore lets a state arm one wait and leave through
     * another, an ambiguity no runtime coordination can repair, so it is refused where it is written.
     *
     * Guarded success siblings stay legal on a state that fans nothing out: there they are ordinary
     * conditional routing and the selector alone answers for them.
     *
     * @param  array<string, list<RaceArm>>  $raceArms
     * @param  array<string, list<JoinArm>>  $joinArms
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition when a state carrying arms declares a second success edge
     */
    public function armedStatesKeepOneSuccessEdge(string $workflow, array $raceArms, array $joinArms, array $transitions): void
    {
        $successEdges = [];
        foreach ($transitions as $transition) {
            if ($transition->trigger === OnTrigger::Success) {
                $successEdges[$transition->from][] = $transition->to;
            }
        }

        foreach (array_keys($raceArms) as $stateKey) {
            if (count($successEdges[$stateKey] ?? []) > 1) {
                throw InvalidWorkflowDefinition::raceWithMoreThanOneSuccessEdge((string) $stateKey, $workflow);
            }
        }

        foreach (array_keys($joinArms) as $stateKey) {
            if (count($successEdges[$stateKey] ?? []) > 1) {
                throw InvalidWorkflowDefinition::joinWithMoreThanOneSuccessEdge((string) $stateKey, $workflow);
            }
        }
    }

    /**
     * The race declaration must be able to dispose of every future it opens. Per race: the state is
     * an activity; at least two arms, or there is no race; each arm's command, outcome and
     * compensation classes exist; no two arms share a command class, since the outbox stamping
     * matches on it; no two arms' outcome classes overlap by inheritance, since the winner lookup
     * matches subtypes exactly as the wait does; and the state may NOT also declare `#[Compensate]`,
     * since a race's compensation is per-arm by construction and a state-level undo beside it would
     * leave the rollback two truths.
     *
     * @param  array<string, list<RaceArm>>  $raceArms
     * @param  array<string, StateType>  $types
     * @param  array<string, mixed>  $compensations  stateKey-keyed `#[Compensate]` declarations
     *
     * @throws InvalidWorkflowDefinition when a race declaration is incoherent
     */
    public function raceArmsAreCoherent(string $workflow, array $raceArms, array $types, array $compensations): void
    {
        foreach ($raceArms as $stateKey => $arms) {
            $stateKey = (string) $stateKey;
            if (($types[$stateKey] ?? null) !== StateType::Activity) {
                throw InvalidWorkflowDefinition::raceArmOnNonActivityState($stateKey, $workflow);
            }
            if (count($arms) < 2) {
                throw InvalidWorkflowDefinition::raceNeedsTwoArms($stateKey, $workflow, count($arms));
            }
            if (isset($compensations[$stateKey])) {
                throw InvalidWorkflowDefinition::raceStateAlsoCompensatable($stateKey, $workflow);
            }

            $commands = [];
            foreach ($arms as $i => $arm) {
                if (trim($arm->arm) === '') {
                    throw InvalidWorkflowDefinition::raceArmBlankName($stateKey, $workflow);
                }
                foreach (['command' => $arm->command, 'wonBy' => $arm->wonBy, 'compensate' => $arm->compensate] as $field => $class) {
                    if (! class_exists($class)) {
                        throw InvalidWorkflowDefinition::raceArmUnknownClass($arm->arm, $field, $class, $stateKey, $workflow);
                    }
                }
                if (isset($commands[$arm->command])) {
                    throw InvalidWorkflowDefinition::raceArmsShareACommand($arm->command, $stateKey, $workflow);
                }
                // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset above, so the value is arbitrary
                $commands[$arm->command] = true;

                // each pair once, the join sibling's shape: visited in both orders, the second is_a
                // is redundant and its removal survives every test; visited once, both directions
                // are load-bearing, since subtyping runs one way only
                foreach ($arms as $j => $other) {
                    if ($i < $j
                        && (is_a($arm->wonBy, $other->wonBy, true) || is_a($other->wonBy, $arm->wonBy, true))) {
                        throw InvalidWorkflowDefinition::raceArmOutcomesOverlap($arm->arm, $other->arm, $stateKey, $workflow);
                    }
                }
            }
        }
    }

    /**
     * The join declaration must be able to account for every future it opens, the race rule's dual
     * plus the join's own two: a state may not declare BOTH `#[RaceArm]`s and `#[JoinArm]`s, since
     * out at the first outcome or out at the last are opposite contracts; and the event classes of
     * ALL arms, `completedBy` and `failedBy` together, must be pairwise disjoint by inheritance,
     * since one arriving event must mean exactly one thing for exactly one arm.
     *
     * @param  array<string, list<JoinArm>>  $joinArms
     * @param  array<string, StateType>  $types
     * @param  array<string, mixed>  $compensations  stateKey-keyed `#[Compensate]` declarations
     * @param  array<string, list<RaceArm>>  $raceArms  stateKey-keyed `#[RaceArm]` declarations
     *
     * @throws InvalidWorkflowDefinition when a join declaration is incoherent
     */
    public function joinArmsAreCoherent(string $workflow, array $joinArms, array $types, array $compensations, array $raceArms): void
    {
        foreach ($joinArms as $stateKey => $arms) {
            $stateKey = (string) $stateKey;
            if (($types[$stateKey] ?? null) !== StateType::Activity) {
                throw InvalidWorkflowDefinition::joinArmOnNonActivityState($stateKey, $workflow);
            }
            if (count($arms) < 2) {
                throw InvalidWorkflowDefinition::joinNeedsTwoArms($stateKey, $workflow, count($arms));
            }
            if (isset($compensations[$stateKey])) {
                throw InvalidWorkflowDefinition::joinStateAlsoCompensatable($stateKey, $workflow);
            }
            if (isset($raceArms[$stateKey])) {
                throw InvalidWorkflowDefinition::joinStateAlsoRaces($stateKey, $workflow);
            }

            $commands = [];
            $events = [];
            foreach ($arms as $arm) {
                if (trim($arm->arm) === '') {
                    throw InvalidWorkflowDefinition::joinArmBlankName($stateKey, $workflow);
                }
                $classes = ['command' => $arm->command, 'completedBy' => $arm->completedBy, 'compensate' => $arm->compensate];
                if ($arm->failedBy !== null) {
                    $classes['failedBy'] = $arm->failedBy;
                }
                foreach ($classes as $field => $class) {
                    if (! class_exists($class)) {
                        throw InvalidWorkflowDefinition::joinArmUnknownClass($arm->arm, $field, $class, $stateKey, $workflow);
                    }
                }
                if (isset($commands[$arm->command])) {
                    throw InvalidWorkflowDefinition::joinArmsShareACommand($arm->command, $stateKey, $workflow);
                }
                // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset above, so the value is arbitrary
                $commands[$arm->command] = true;

                $events[] = ['arm' => $arm->arm, 'field' => 'completedBy', 'class' => $arm->completedBy];
                if ($arm->failedBy !== null) {
                    $events[] = ['arm' => $arm->arm, 'field' => 'failedBy', 'class' => $arm->failedBy];
                }
            }

            foreach ($events as $i => $entry) {
                foreach ($events as $j => $other) {
                    if ($i < $j
                        && (is_a($entry['class'], $other['class'], true) || is_a($other['class'], $entry['class'], true))) {
                        throw InvalidWorkflowDefinition::joinArmEventsOverlap($entry['arm'], $entry['field'], $other['arm'], $other['field'], $stateKey, $workflow);
                    }
                }
            }
        }
    }

    /**
     * The assembled race shape: the settling wait, derived from the issuing state's success edge,
     * must be a WAIT whose accepted events are EXACTLY the arms' outcome classes, no aliases and no
     * foreign exit. An event no arm owns leaving that wait would strand the losers undisposed, the
     * silent half-race this rule refuses; and every arm must be able to win through the wait that
     * settles it, or its command is a fire-and-forget wearing a racer's number.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a race's settling wait cannot dispose of every arm
     */
    public function racesSettleOnTheirOwnOutcomes(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof ActivityState || $state->race === null) {
                continue;
            }

            $wait = $states[$state->race->settledBy] ?? null;
            if (! $wait instanceof WaitState) {
                throw InvalidWorkflowDefinition::raceSettlesOnNonWait($state->key, $state->race->settledBy, $workflow);
            }
            if ($wait->eventTypes !== []) {
                throw InvalidWorkflowDefinition::raceWaitUsesAliases($state->key, $wait->key, $workflow);
            }

            $wonBy = array_map(static fn ($arm): string => $arm->wonBy, $state->race->arms);
            foreach ($wonBy as $armName => $outcome) {
                if (! in_array($outcome, $wait->eventClasses, true)) {
                    throw InvalidWorkflowDefinition::raceArmOutcomeNotAwaited((string) $armName, $outcome, $wait->key, $workflow);
                }
            }
            foreach ($wait->eventClasses as $accepted) {
                if (! in_array($accepted, $wonBy, true)) {
                    throw InvalidWorkflowDefinition::raceWaitAcceptsForeignEvent($accepted, $wait->key, $state->key, $workflow);
                }
            }
        }
    }

    /**
     * The joining wait accepts exactly the arms' events, the race rule's dual over the union of
     * `completedBy` and `failedBy`: an arm whose events the wait refuses could never complete nor
     * fail, and a foreign accepted event would carry the saga out with arrivals unaccounted.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition
     */
    public function joinsCompleteOnTheirOwnOutcomes(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof ActivityState || $state->join === null) {
                continue;
            }

            $wait = $states[$state->join->joinedBy] ?? null;
            if (! $wait instanceof WaitState) {
                throw InvalidWorkflowDefinition::joinSettlesOnNonWait($state->key, $state->join->joinedBy, $workflow);
            }
            if ($wait->eventTypes !== []) {
                throw InvalidWorkflowDefinition::joinWaitUsesAliases($state->key, $wait->key, $workflow);
            }

            $routed = [];
            foreach ($wait->transitions as $transition) {
                if ($transition->onEvent !== null) {
                    $routed[] = $transition->onEvent;
                }
            }

            $armEvents = [];
            foreach ($state->join->arms as $armName => $arm) {
                $armEvents[] = $arm->completedBy;
                if (! in_array($arm->completedBy, $wait->eventClasses, true)) {
                    throw InvalidWorkflowDefinition::joinArmEventNotAwaited((string) $armName, 'completedBy', $arm->completedBy, $wait->key, $workflow);
                }
                // only the LAST completion crosses, so every completion must leave through the SAME
                // catch-all edge: an individually routed one would make the target depend on which
                // arm happened to arrive last
                if (in_array($arm->completedBy, $routed, true)) {
                    throw InvalidWorkflowDefinition::joinCompletionIndividuallyRouted((string) $armName, $arm->completedBy, $wait->key, $workflow);
                }
                if ($arm->failedBy !== null) {
                    $armEvents[] = $arm->failedBy;
                    if (! in_array($arm->failedBy, $wait->eventClasses, true)) {
                        throw InvalidWorkflowDefinition::joinArmEventNotAwaited((string) $armName, 'failedBy', $arm->failedBy, $wait->key, $workflow);
                    }
                    // a failure must ride its OWN edge: through the catch-all it would cross into
                    // the success path, a definitive refusal dressed as the join's completion
                    if (! in_array($arm->failedBy, $routed, true)) {
                        throw InvalidWorkflowDefinition::joinFailureNotRouted((string) $armName, $arm->failedBy, $wait->key, $workflow);
                    }
                }
            }
            foreach ($wait->eventClasses as $accepted) {
                if (! in_array($accepted, $armEvents, true)) {
                    throw InvalidWorkflowDefinition::joinWaitAcceptsForeignEvent($accepted, $wait->key, $state->key, $workflow);
                }
            }
        }
    }

    /**
     * The engine owns the deadline of a wait that gates an issued effect, the `success`-target of an
     * activity; it re-arms and escalates, never finalizes. Declaring a `timeout` transition is a money-bug
     * footgun in an async pipeline: a timeout cannot tell a committed-but-unconfirmed effect from a failed
     * one, so finalizing on it would discard a possibly committed effect or duplicate it on a later
     * rollback. Reject it at build. This is decoupled from compensatability: a non-compensatable issued
     * effect is gated too.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when an effect-gating wait declares an `#[On(trigger: 'timeout')]`
     *
     * @see StepPolicy
     */
    public function engineOwnsEffectGatingDeadlines(string $workflow, array $states): void
    {
        foreach ($states as $wait) {
            if (! $wait instanceof WaitState || ! EffectGating::gates($states, $wait->key)) {
                continue;
            }

            foreach ($wait->transitions as $transition) {
                if ($transition->trigger === OnTrigger::Timeout) {
                    throw InvalidWorkflowDefinition::timeoutOwnedByEngineOnEffectGatingWait($wait->key, $workflow);
                }
            }
        }
    }

    /**
     * A compensatable activity whose success-target is a gating wait must declare `confirmedBy`. At that
     * wait, the step's effect is not yet proven: for an issued command, progression only proves the
     * dispatch was written; a settle via dead-letter or a forced cancel there runs the positional rollback,
     * where an untracked step is eligible and would undo an effect that may never have happened.
     * `confirmedBy`, usually the success event the wait consumes, makes the entry verifiable, so the
     * rollback skips-and-flags it until the effect is confirmed.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a compensatable issue-and-wait step has no confirmedBy
     */
    public function compensableGatingStepsDeclareConfirmedBy(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof ActivityState || $state->compensation === null || $state->compensationConfirmedBy !== null) {
                continue;
            }

            foreach ($state->transitions as $transition) {
                if ($transition->trigger === OnTrigger::Success && ($states[$transition->to] ?? null) instanceof WaitState) {
                    throw InvalidWorkflowDefinition::compensationUnverifiableAtGatingWait($state->key, $transition->to, $workflow);
                }
            }
        }
    }
}
