<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\SignalResult;
use Storm\Saga\Workflow\SpawnSlot;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Reflects a `#[Workflow]` class into an immutable WorkflowDefinition: reads `#[State]`, `#[On]`,
 * `#[WaitFor]`, `#[Retry]`, `#[Start]`, and `#[Signal]`, builds the state subtypes by resolving each
 * activity from the `$activities` container and binding guard, matcher, and signal-handler methods to the
 * given instance, and wires the transitions. A malformed declaration fails fast with
 * InvalidWorkflowDefinition.
 *
 * It takes the workflow instance, not just the class, so guard and matcher closures bind to it; the
 * discovery wiring hands tagged instances, and tests pass `new SomeWorkflow()`.
 */
final readonly class WorkflowBuilder
{
    private DeclarationReader $reader;

    private WorkflowBinder $binder;

    private StateAssembler $assembler;

    private DefinitionValidator $rules;

    public function __construct(
        ContainerInterface $activities,
    ) {
        $this->reader = new DeclarationReader;
        $this->binder = new WorkflowBinder($activities);
        $this->assembler = new StateAssembler($this->binder);
        $this->rules = new DefinitionValidator;
    }

    /**
     * @throws InvalidWorkflowDefinition when the class is malformed, such as no `#[Workflow]`, no states,
     *                                   an unknown start or transition state, or an unresolvable activity
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function build(object $workflow): WorkflowDefinition
    {
        $class = new ReflectionClass($workflow);

        $attribute = ($class->getAttributes(Workflow::class)[0] ?? null)?->newInstance()
            ?? throw InvalidWorkflowDefinition::noWorkflowAttribute($class->getName());
        $name = $attribute->name;

        // first, since every refusal below names the workflow: a blank name would report on nothing
        if (trim($name) === '') {
            throw InvalidWorkflowDefinition::workflowNameBlank($class->getName());
        }

        // reflect and group: raw declarations, nothing constructed yet
        $declared = array_map(static fn ($a): StateAttribute => $a->newInstance(), $class->getAttributes(StateAttribute::class));
        $ons = array_map(static fn ($a): On => $a->newInstance(), $class->getAttributes(On::class));
        $retries = $this->reader->retriesByState($class, $name);
        $waits = $this->reader->waitsByState($class, $name);
        $schedules = $this->reader->schedulesByState($class, $name);
        $compensations = $this->reader->compensationsByState($class, $name);
        $breakers = $this->reader->circuitBreakersByState($class, $name);
        $fallbacks = $this->binder->fallbacksByState($class, $workflow, $name);
        $signals = $this->reader->signalsByClass($class, $name);
        $retimables = $this->reader->retimablesByState($class, $name);
        $raceArms = $this->reader->raceArmsByState($class, $name);
        $joinArms = $this->reader->joinArmsByState($class, $name);
        $spawns = array_map(static fn ($a): Spawns => $a->newInstance(), $class->getAttributes(Spawns::class));

        $startAttribute = ($class->getAttributes(Start::class)[0] ?? null)?->newInstance();
        $start = $startAttribute->state ?? ($declared[0]->key ?? null);
        $startAfterSeconds = $startAttribute?->afterSeconds;

        // the declaration pass: every reference judged on the key set, before any state exists
        $this->rules->checkDeclaration(new Declaration(
            workflow: $name,
            declared: $declared,
            transitions: $ons,
            decoratorsByLabel: [
                'Retry' => $retries, 'Compensate' => $compensations, 'CircuitBreaker' => $breakers, 'Fallback' => $fallbacks,
            ],
            waits: $waits,
            start: $start,
            onGlobalTimeout: $attribute->onGlobalTimeout,
            globalTimeout: $attribute->globalTimeout,
            schedules: $schedules,
            signals: $signals,
            spawns: $spawns,
            retimables: $retimables,
            raceArms: $raceArms,
            joinArms: $joinArms,
            reuse: $attribute->reuse,
        ));

        // group the edges by their from-state; guards are bound here, and a missing method is a constructive failure
        $transitions = [];
        foreach ($ons as $on) {
            $transitions[$on->from][] = new Transition($on->trigger, $on->to, $this->binder->bind($class, $on->guard, $workflow, $name), $on->onEvent);
        }

        // signal handlers bound to the instance; the map's key set IS the definition's accepted signals
        $signalHandlers = array_map(function ($signal) use ($workflow, $name, $class) {
            return $this->binder->bindSignalHandler($class, $signal, $workflow, $name);
        }, $signals);

        // each state is born complete; transitions readonly at the constructor, nothing mutated after
        $states = [];
        foreach ($declared as $stateAttribute) {
            $states[$stateAttribute->key] = $this->assembler->makeState($stateAttribute, $name, $retries, $waits, $schedules, $compensations, $breakers, $fallbacks, $workflow, $class, $transitions[$stateAttribute->key] ?? [], $retimables, $raceArms, $joinArms);
        }

        // the assembled pass: shape rules that need the finished map: gating, timeout-vs-global, reachability
        $this->rules->checkAssembled(
            $name,
            $states,
            array_map(static fn (Spawns $spawn): string => $spawn->awaitedBy, $spawns),
            $attribute->globalTimeout,
            $attribute->onGlobalTimeout,
        );

        if ($attribute->retryBudget !== null && $attribute->retryBudget < 1) {
            throw InvalidWorkflowDefinition::retryBudgetNotPositive($name, $attribute->retryBudget);
        }

        // the birth delay must be a real duration, and it must leave the global budget something to
        // live on: a delay at or past the cap is a saga that expires before it ever runs
        if ($startAfterSeconds !== null && $startAfterSeconds < 1) {
            throw InvalidWorkflowDefinition::startDelayNotPositive($name, $startAfterSeconds);
        }
        if ($startAfterSeconds !== null && $attribute->globalTimeout !== null && $startAfterSeconds >= $attribute->globalTimeout) {
            throw InvalidWorkflowDefinition::startDelayReachesGlobalTimeout($name, $startAfterSeconds, $attribute->globalTimeout);
        }

        // the declared spawn slots, keyed; the rules proved slots unique, valid, and awaited by a real wait
        $spawnSlots = [];
        foreach ($spawns as $spawn) {
            $spawnSlots[$spawn->slot] = new SpawnSlot($spawn->slot, $spawn->workflow, $spawn->awaitedBy, $spawn->indexed);
        }

        // the state contract's three class-level declarations, bound by NAME rather than an attribute
        // pointer: the optional write-point validator, the compiled exposure allowlist, and the
        // optional one-hop migrator the executor chains at load
        $stateValidator = $class->hasMethod('validateState')
            ? $class->getMethod('validateState')->getClosure($workflow)
            : null;
        $stateMigrator = $class->hasMethod('migrateState')
            ? $class->getMethod('migrateState')->getClosure($workflow)
            : null;
        $exposedStateKeys = $this->reader->exposedStateKeys($class, $name);

        /**
         * @var string $start checkDeclaration proved it exists
         * @var array<class-string, Closure(object, array<string, mixed>): SignalResult> $signalHandlers the rules proved each key concrete, the binder each closure's shape
         */
        return new WorkflowDefinition($name, $states, $start, $attribute->globalTimeout, $attribute->onGlobalTimeout, $attribute->compensation, $attribute->version, $attribute->label, $attribute->retryBudget, $signalHandlers, $spawnSlots, $attribute->reuse, $attribute->stateVersion, $stateValidator, $exposedStateKeys, $stateMigrator, $startAfterSeconds);
    }
}
