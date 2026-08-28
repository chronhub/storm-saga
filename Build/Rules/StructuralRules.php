<?php

declare(strict_types=1);

namespace Storm\Saga\Build\Rules;

use ReflectionClass;
use ReflectionException;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The structural family of the definition rules: keys, state kinds, transition targets and
 * trigger compatibility, judged on the declared key set.
 */
final readonly class StructuralRules
{
    /**
     * @param  list<StateAttribute>  $declared
     *
     * @throws InvalidWorkflowDefinition
     */
    public function someStateIsDeclared(string $workflow, array $declared): void
    {
        if ($declared === []) {
            throw InvalidWorkflowDefinition::noStates($workflow);
        }
    }

    /**
     * A state key is an identifier every other declaration quotes, so a blank one is a typo that would
     * still resolve: the edges naming `''` would connect, and the store and console would print nothing
     * where the state belongs. The sibling of the blank `#[ExposesState]` key and the blank `#[Spawns]`
     * slot, both refused for the same reason.
     *
     * @param  list<StateAttribute>  $declared
     *
     * @throws InvalidWorkflowDefinition when a declared key is empty or whitespace
     */
    public function everyStateKeyIsNamed(string $workflow, array $declared): void
    {
        foreach ($declared as $state) {
            if (trim($state->key) === '') {
                throw InvalidWorkflowDefinition::stateKeyBlank($workflow);
            }
        }
    }

    /**
     * @param  list<StateAttribute>  $declared
     * @return array<string, StateType> the key-to-declared-type map the other rules read
     *
     * @throws InvalidWorkflowDefinition
     */
    public function everyStateKeyIsUnique(string $workflow, array $declared): array
    {
        $types = [];
        foreach ($declared as $state) {
            if (isset($types[$state->key])) {
                throw InvalidWorkflowDefinition::duplicateState($state->key, $workflow);
            }
            $types[$state->key] = $state->type;
        }

        return $types;
    }

    /**
     * A retry / compensation / circuit-breaker / fallback only means anything on an activity state; on a
     * wait, final, or unknown state it would be silently dropped, so reject it at build.
     *
     * @param  array<string, array<string, mixed>>  $decoratorsByLabel
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function decoratorsTargetActivityStates(string $workflow, array $decoratorsByLabel, array $types): void
    {
        foreach ($decoratorsByLabel as $label => $map) {
            foreach (array_keys($map) as $stateKey) {
                if (($types[$stateKey] ?? null) !== StateType::Activity) {
                    throw InvalidWorkflowDefinition::decoratesNonActivityState($label, (string) $stateKey, $workflow);
                }
            }
        }
    }

    /**
     * A `#[WaitFor]` only means anything on a wait state, the inverse of the decorator rule.
     *
     * @param  list<string>  $waitForKeys
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function waitForTargetsWaitStates(string $workflow, array $waitForKeys, array $types): void
    {
        foreach ($waitForKeys as $stateKey) {
            if (($types[$stateKey] ?? null) !== StateType::Wait) {
                throw InvalidWorkflowDefinition::waitForOnNonWaitState($stateKey, $workflow);
            }
        }
    }

    /**
     * @param  list<On>  $transitions
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function transitionsConnectKnownStates(string $workflow, array $transitions, array $types): void
    {
        foreach ($transitions as $on) {
            if (! isset($types[$on->from])) {
                throw InvalidWorkflowDefinition::transitionFromUnknownState($on->from, $workflow);
            }
            if (! isset($types[$on->to])) {
                throw InvalidWorkflowDefinition::transitionToUnknownState($on->to, $workflow);
            }
        }
    }

    /**
     * The selector answers a fired trigger with the FIRST transition whose trigger, `onEvent` scope and
     * guard match, so an unguarded edge answers for every later sibling in its scope: those siblings are
     * dead code, which a duplicated `#[On]` produces in silence. Scope is `(from, trigger, onEvent)`,
     * since a specific event edge and a catch-all never compete for one fire.
     *
     * The mirror of the rules that demand an unguarded route: those ask that one exist, this asks that
     * it not come too early.
     *
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition when an unguarded transition shadows a later sibling
     *
     * @see TransitionSelector
     */
    public function unguardedTransitionsShadowNoSibling(string $workflow, array $transitions): void
    {
        $answered = [];
        foreach ($transitions as $transition) {
            $scope = $transition->from."\0".$transition->trigger->value."\0".($transition->onEvent ?? '');
            if (isset($answered[$scope])) {
                throw InvalidWorkflowDefinition::transitionUnreachable($transition->from, $transition->to, $transition->trigger->value, $workflow);
            }
            if ($transition->guard === null) {
                // @infection-ignore-all; equivalent: the check above reads this only via isset(), which is
                // value-agnostic, since true vs. false both register the scope as "answered"; only presence matters
                $answered[$scope] = true;
            }
        }
    }

    /**
     * `onEvent` scopes an edge to an event class; meaningless off the `Event` trigger.
     *
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition
     */
    public function onEventRidesAnEventTrigger(string $workflow, array $transitions): void
    {
        foreach ($transitions as $on) {
            if ($on->onEvent !== null && $on->trigger !== OnTrigger::Event) {
                throw InvalidWorkflowDefinition::onEventOnNonEventTrigger($on->from, $on->onEvent, $workflow);
            }
        }
    }

    /**
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition
     */
    public function onEventClassesExist(string $workflow, array $transitions): void
    {
        foreach ($transitions as $on) {
            if ($on->onEvent !== null && ! class_exists($on->onEvent) && ! interface_exists($on->onEvent)) {
                throw InvalidWorkflowDefinition::unknownOnEventClass($on->onEvent, $on->from, $workflow);
            }
        }
    }

    /**
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function startStateExists(string $workflow, ?string $start, array $types): void
    {
        if ($start === null || ! isset($types[$start])) {
            throw InvalidWorkflowDefinition::unknownStartState((string) $start, $workflow);
        }
    }

    /**
     * An `onEvent` must be a concrete class: routing compares the delivered event's concrete class in
     * `TransitionSelector`, where an exact edge wins over the catch-all, so an interface or abstract class
     * there can never be selected; the wait may still match the event by `instanceof`, then halt with no
     * route. The existence check `onEventClassesExist()` ran first, so by here the name resolves.
     *
     * @param  list<On>  $transitions
     *
     * @throws InvalidWorkflowDefinition when an onEvent names an interface or abstract class
     * @throws ReflectionException when reflection fails to resolve the class
     */
    public function onEventTargetsAConcreteClass(string $workflow, array $transitions): void
    {
        foreach ($transitions as $on) {
            if ($on->onEvent === null) {
                continue;
            }
            if (interface_exists($on->onEvent) || new ReflectionClass($on->onEvent)->isAbstract()) {
                throw InvalidWorkflowDefinition::onEventNotConcrete($on->onEvent, $on->from, $workflow);
            }
        }
    }

    /**
     * A transition's trigger must be one its from-state can ever yield: an activity yields
     * success/failure/timeout, a wait event/timeout, a schedule only schedule. Any other pairing is a dead
     * edge the runner never selects, where the author believes in a route that does not exist. A final
     * state is deliberately exempt: a transition out of it is dead by construction and kept constructible
     * for parity, as noted on {@see FinalState}.
     *
     * @param  list<On>  $transitions
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition when a trigger is foreign to its from-state's kind
     */
    public function transitionTriggersMatchTheStateKind(string $workflow, array $transitions, array $types): void
    {
        foreach ($transitions as $on) {
            $kind = $types[$on->from] ?? null;
            if ($kind === null || $kind === StateType::Final) {
                continue; // unknown 'from' are already rejected; final is the documented parity exemption
            }

            $legal = match ($kind) {
                StateType::Activity => [OnTrigger::Success, OnTrigger::Failure, OnTrigger::Timeout],
                StateType::Wait => [OnTrigger::Event, OnTrigger::Timeout],
                StateType::Schedule => [OnTrigger::Schedule],
            };

            if (! in_array($on->trigger, $legal, true)) {
                throw array_map(static fn (OnTrigger $trigger): string => $trigger->value, $legal)
                        |> (static fn ($x) => implode(', ', $x))
                        |> (static fn ($x) => InvalidWorkflowDefinition::triggerForeignToStateKind($on->trigger->value, $on->from, $kind->value, $x, $workflow));
            }
        }
    }

    /**
     * A `#[State]`'s optional fields must belong to its kind: `timeoutSeconds` and `activity` are read for
     * an activity state only; on a wait, schedule, or final state they would be silently ignored, with the
     * author believing in a timer or an executor that is not there. A wait's deadline lives on `#[WaitFor]`,
     * a schedule's cadence on `#[Schedule]`.
     *
     * @param  list<StateAttribute>  $declared
     *
     * @throws InvalidWorkflowDefinition when a state field is foreign to the declared kind
     */
    public function stateFieldsMatchTheKind(string $workflow, array $declared): void
    {
        foreach ($declared as $state) {
            if ($state->type === StateType::Activity) {
                continue;
            }
            if ($state->timeoutSeconds !== null) {
                throw InvalidWorkflowDefinition::stateFieldForeignToKind(
                    'timeoutSeconds', $state->key, $state->type->value,
                    'A wait\'s deadline lives on #[WaitFor] (heartbeatSeconds / deadlineSeconds); a schedule\'s cadence on #[Schedule].',
                    $workflow,
                );
            }
            if ($state->activity !== null) {
                throw InvalidWorkflowDefinition::stateFieldForeignToKind(
                    'activity', $state->key, $state->type->value,
                    'Only an activity state runs one.',
                    $workflow,
                );
            }
        }
    }

    /**
     * Every `#[Signal]` names a resolvable concrete class. The runtime lookup is by the incoming signal's
     * exact class via {@see WorkflowDefinition::signalHandlerFor()}, so a declaration naming an unknown
     * class, an interface, or an abstract class could never match anything: dead config, refused at build.
     *
     * @param  array<string, Signal>  $signals
     *
     * @throws InvalidWorkflowDefinition when a signal class is unknown or not concrete
     */
    public function signalClassesAreConcrete(string $workflow, array $signals): void
    {
        foreach ($signals as $signal) {
            // interface_exists rides the existence test, the onEvent twin's shape: an interface is
            // a symbol that exists and cannot match, and "does not exist" would send its author
            // hunting a typo instead of reading the exact-class rule the dedicated refusal names
            if ($signal->signal === '' || (! class_exists($signal->signal) && ! interface_exists($signal->signal))) {
                throw InvalidWorkflowDefinition::unknownSignalClass($signal->signal, $workflow);
            }
            if (interface_exists($signal->signal) || new ReflectionClass($signal->signal)->isAbstract()) {
                throw InvalidWorkflowDefinition::signalClassNotConcrete($signal->signal, $workflow);
            }
        }
    }

    /**
     * A wait's event list is well-formed: no empty entry, and no namespaced string that resolves to
     * nothing, since a mistyped FQCN would silently become a type alias that never matches and the wait
     * would never resolve. A wait that accepts no events must carry a deadline as a durable sleep; with
     * neither, the state can never be left.
     *
     * @param  array<string, WaitFor>  $waits
     *
     * @throws InvalidWorkflowDefinition when an event entry is empty or a dressed-as-class typo, or the wait is unleavable
     */
    public function waitEventsAreWellFormed(string $workflow, array $waits): void
    {
        foreach ($waits as $wait) {
            foreach ($wait->events as $event) {
                if ($event === '') {
                    throw InvalidWorkflowDefinition::waitEventEmpty($wait->state, $workflow);
                }
                if (! interface_exists($event) && ! class_exists($event) && str_contains($event, '\\')) {
                    throw InvalidWorkflowDefinition::unknownWaitEventClass($event, $wait->state, $workflow);
                }
            }

            $hasDeadline = $wait->deadlineSeconds !== null
                || $wait->deadlineBusinessDays !== null || $wait->deadlineBusinessHours !== null;
            if ($wait->events === [] && ! $hasDeadline) {
                throw InvalidWorkflowDefinition::waitAcceptsNothing($wait->state, $workflow);
            }
        }
    }
}
