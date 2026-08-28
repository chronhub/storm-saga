<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\Schedule as ScheduleAttribute;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Storm\Saga\Workflow\Fallback\FallbackPolicy;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\JoinArmSlot;
use Storm\Saga\Workflow\JoinPolicy;
use Storm\Saga\Workflow\RaceArmSlot;
use Storm\Saga\Workflow\RacePolicy;
use Storm\Saga\Workflow\RetimePolicy;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;

/**
 * The compile's assembly phase: each declared state is born complete and immutable, its kind
 * chosen by the declaration, its policies attached, its transitions readonly at the constructor,
 * everything named resolved through the binder. The assembled map is what the shape rules judge.
 */
final readonly class StateAssembler
{
    public function __construct(
        private WorkflowBinder $binder,
    ) {}

    /**
     * @param  ReflectionClass<object>  $class
     * @param  array<string, RetryPolicy>  $retries
     * @param  array<string, WaitFor>  $waits
     * @param  array<string, ScheduleAttribute>  $schedules  each stateKey to its `#[Schedule]`
     * @param  array<string, Compensate>  $compensations  each stateKey to its `#[Compensate]`
     * @param  array<string, CircuitBreakerPolicy>  $breakers  each stateKey to its circuit-breaker policy
     * @param  array<string, FallbackPolicy>  $fallbacks  each stateKey to its fallback chain
     * @param  list<Transition>  $transitions  this state's outgoing edges, already validated & bound
     * @param  array<string, RetimePolicy>  $retimables  each stateKey to its `#[Retimable]` grant
     * @param  array<string, list<RaceArm>>  $raceArms  each stateKey to its declared `#[RaceArm]`s
     * @param  array<string, list<JoinArm>>  $joinArms  each stateKey to its declared `#[JoinArm]`s
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function makeState(StateAttribute $attribute, string $workflow, array $retries, array $waits, array $schedules, array $compensations, array $breakers, array $fallbacks, object $instance, ReflectionClass $class, array $transitions, array $retimables = [], array $raceArms = [], array $joinArms = []): State
    {
        return match ($attribute->type) {
            StateType::Activity => $this->activityState($attribute, $workflow, $retries, $compensations, $breakers, $fallbacks, $transitions, $raceArms[$attribute->key] ?? [], $joinArms[$attribute->key] ?? []),
            StateType::Wait => $this->waitState($attribute, $workflow, $waits, $instance, $class, $transitions, $retimables[$attribute->key] ?? null),
            StateType::Schedule => $this->scheduleState($attribute, $workflow, $schedules, $instance, $class, $transitions),
            StateType::Final => new FinalState($attribute->key, $transitions),
        };
    }

    /**
     * @param  array<string, RetryPolicy>  $retries
     * @param  array<string, Compensate>  $compensations  each stateKey to its `#[Compensate]`
     * @param  array<string, CircuitBreakerPolicy>  $breakers  each stateKey to its circuit-breaker policy
     * @param  array<string, FallbackPolicy>  $fallbacks  each stateKey to its fallback chain
     * @param  list<Transition>  $transitions
     * @param  list<RaceArm>  $raceArms  this state's declared arms, empty for the raceless common case
     * @param  list<JoinArm>  $joinArms  this state's declared join arms, empty for the joinless common case
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function activityState(StateAttribute $attribute, string $workflow, array $retries, array $compensations, array $breakers, array $fallbacks, array $transitions, array $raceArms = [], array $joinArms = []): ActivityState
    {
        $activityClass = $attribute->activity
            ?? throw InvalidWorkflowDefinition::activityStateMissingActivity($attribute->key, $workflow);

        $activity = $this->binder->activity($activityClass, $attribute->key, $workflow);

        return new ActivityState(
            $attribute->key,
            $activity,
            $retries[$attribute->key] ?? null,
            $attribute->timeoutSeconds !== null ? new Timeout($attribute->timeoutSeconds) : null,
            $this->binder->resolveCompensation($compensations, $attribute->key, $workflow),
            $breakers[$attribute->key] ?? null,
            $compensations[$attribute->key]->confirmedBy ?? null,
            $fallbacks[$attribute->key] ?? null,
            $transitions,
            $this->racePolicy($raceArms, $transitions, $attribute->key, $workflow),
            $this->joinPolicy($joinArms, $transitions, $attribute->key, $workflow),
        );
    }

    /**
     * @param  array<string, WaitFor>  $waits
     * @param  ReflectionClass<object>  $class
     * @param  list<Transition>  $transitions
     *
     * @throws ReflectionException
     */
    private function waitState(StateAttribute $attribute, string $workflow, array $waits, object $instance, ReflectionClass $class, array $transitions, ?RetimePolicy $retime = null): WaitState
    {
        $wait = $waits[$attribute->key]
            ?? throw InvalidWorkflowDefinition::waitStateMissingWaitFor($attribute->key, $workflow);

        [$classes, $types] = $this->splitEvents($wait->events);
        [$extract, $extractMap] = $this->binder->resolveExtract($class, $wait->extract, $instance, $attribute->key, $workflow);

        // the wait's timeout: heartbeat / wall-clock-deadline, both `second`, XOR a business-time deadline
        // in days/hours, resolved by the BusinessCalendar at the arm. At most one is set; TimeRules guards this.
        $hasBusinessDeadline = $wait->deadlineBusinessDays !== null || $wait->deadlineBusinessHours !== null;
        $timeout = match (true) {
            $wait->heartbeatSeconds !== null => new Timeout($wait->heartbeatSeconds),
            $wait->deadlineSeconds !== null => new Timeout($wait->deadlineSeconds),
            $hasBusinessDeadline => new Timeout(0, $wait->deadlineBusinessDays, $wait->deadlineBusinessHours),
            default => null,
        };

        // a deadline finalizes: desugar `onDeadline` into a synthesized timeout transition, so the engine
        // drives it through the same On(timeout) edge machinery as any other transition; a heartbeat
        // synthesizes nothing, it only re-arms. TimeRules pairs deadline and onDeadline, both or neither.
        // Equivalent mutants on this condition: under the declaration invariants, wall XOR business, and
        // deadline-and-onDeadline both-or-neither, the whole guard reduces to `onDeadline !== null`, so the
        // or-negation and and-to-or rewrites cannot produce a divergent input.
        if (($wait->deadlineSeconds !== null || $hasBusinessDeadline) && $wait->onDeadline !== null) {
            $transitions = [...$transitions, new Transition(OnTrigger::Timeout, $wait->onDeadline)];
        }

        return new WaitState(
            $attribute->key,
            $classes,
            $types,
            $this->binder->bind($class, $wait->matcher, $instance, $workflow),
            $timeout,
            $wait->retriable,
            $extract,
            $extractMap,
            $transitions,
            $retime,
        );
    }

    /**
     * Split mixed `#[WaitFor]` events into resolvable class-strings and stable type aliases. Alias matching
     * at runtime goes through the `EventResolver` port, not here.
     *
     * @param  list<string>  $events
     * @return array{0: list<class-string>, 1: list<string>}
     *
     * @see EventResolver
     */
    private function splitEvents(array $events): array
    {
        $classes = [];
        $types = [];

        foreach ($events as $event) {
            if (class_exists($event) || interface_exists($event)) {
                $classes[] = $event;
            } elseif ($event !== '') {
                $types[] = $event;
            }
        }

        return [$classes, $types];
    }

    /**
     * Assemble one state's race: the settling wait is DERIVED from the state's own success edge,
     * since the graph is the single source of the topology, and each arm's compensation resolves
     * through the activity locator like a `#[Compensate]` activity does. The declaration rules
     * already proved the arm set coherent; the assembled shape rules judge the derived wait later.
     *
     * @param  list<RaceArm>  $raceArms
     * @param  list<Transition>  $transitions
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function racePolicy(array $raceArms, array $transitions, string $stateKey, string $workflow): ?RacePolicy
    {
        if ($raceArms === []) {
            return null;
        }

        $settledBy = null;
        foreach ($transitions as $transition) {
            if ($transition->trigger === OnTrigger::Success) {
                $settledBy = $transition->to;
                // @infection-ignore-all; equivalent by construction, break to continue: a state
                // carrying race arms is refused at declaration unless it holds EXACTLY ONE success
                // edge, so the walk can never meet a second one to overwrite this with
                break;
            }
        }
        if ($settledBy === null) {
            throw InvalidWorkflowDefinition::raceWithoutASuccessEdge($stateKey, $workflow);
        }

        $arms = [];
        foreach ($raceArms as $arm) {
            $compensation = $this->binder->activity($arm->compensate, $stateKey, $workflow);
            /** @var class-string $command proven concrete by the declaration rules */
            $command = $arm->command;
            /** @var class-string $wonBy proven concrete by the declaration rules */
            $wonBy = $arm->wonBy;
            $arms[$arm->arm] = new RaceArmSlot($arm->arm, $command, $wonBy, $compensation);
        }

        return new RacePolicy($settledBy, $arms);
    }

    /**
     * Assemble one state's join: the joining wait is DERIVED from the state's own success edge, by
     * the same derivation and the same arm resolution the race uses.
     *
     * @param  list<JoinArm>  $joinArms
     * @param  list<Transition>  $transitions
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function joinPolicy(array $joinArms, array $transitions, string $stateKey, string $workflow): ?JoinPolicy
    {
        if ($joinArms === []) {
            return null;
        }

        $joinedBy = null;
        foreach ($transitions as $transition) {
            if ($transition->trigger === OnTrigger::Success) {
                $joinedBy = $transition->to;
                // @infection-ignore-all; equivalent by construction, break to continue: a state
                // carrying join arms is refused at declaration unless it holds EXACTLY ONE success
                // edge, so the walk can never meet a second one to overwrite this with
                break;
            }
        }
        if ($joinedBy === null) {
            throw InvalidWorkflowDefinition::joinWithoutASuccessEdge($stateKey, $workflow);
        }

        $arms = [];
        foreach ($joinArms as $arm) {
            $compensation = $this->binder->activity($arm->compensate, $stateKey, $workflow);
            /** @var class-string $command proven concrete by the declaration rules */
            $command = $arm->command;
            /** @var class-string $completedBy proven concrete by the declaration rules */
            $completedBy = $arm->completedBy;
            /** @var class-string|null $failedBy proven concrete by the declaration rules when present */
            $failedBy = $arm->failedBy;
            $arms[$arm->arm] = new JoinArmSlot($arm->arm, $command, $completedBy, $failedBy, $compensation);
        }

        return new JoinPolicy($joinedBy, $arms);
    }

    /**
     * @param  array<string, ScheduleAttribute>  $schedules
     * @param  ReflectionClass<object>  $class
     * @param  list<Transition>  $transitions
     *
     * @throws ReflectionException
     */
    private function scheduleState(StateAttribute $attribute, string $workflow, array $schedules, object $instance, ReflectionClass $class, array $transitions): ScheduleState
    {
        $schedule = $schedules[$attribute->key]
            ?? throw InvalidWorkflowDefinition::scheduleStateMissingSchedule($attribute->key, $workflow);

        [$cadence, $cronResolver, $intervalResolver] = $this->binder->resolveCadenceSource($schedule, $instance, $class, $workflow);

        return new ScheduleState(
            $attribute->key,
            $cadence,
            new CatchUpPolicy($schedule->catchUp, $schedule->catchUpLimit, $schedule->catchUpWindowSeconds),
            $transitions,
            $cronResolver,
            $schedule->spreadSeconds,
            $intervalResolver,
            // the declared per-instance source, kept beside the built closure: the resolver is
            // opaque at description time, the var/method NAME is the declarative truth it came from.
            // TimeRules admits exactly ONE cadence source, so the two operands of each coalesce
            // below are never both set and swapping them cannot diverge.
            // @infection-ignore-all; equivalent: exactly one cadence source is declarable
            $schedule->cronVar ?? $schedule->intervalVar,
            // @infection-ignore-all; equivalent: exactly one cadence source is declarable
            $schedule->cronMethod ?? $schedule->intervalMethod,
        );
    }
}
