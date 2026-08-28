<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\Fallback;
use Storm\Saga\Attributes\Schedule as ScheduleAttribute;
use Storm\Saga\Attributes\Signal as SignalAttribute;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\Cadence\Cadence;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\Fallback\ActivityFallback;
use Storm\Saga\Workflow\Fallback\FallbackCandidate;
use Storm\Saga\Workflow\Fallback\FallbackPolicy;
use Storm\Saga\Workflow\Fallback\FallbackStrategy;
use Storm\Saga\Workflow\Fallback\StaticFallback;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\SignalResult;

/**
 * The compile's binding phase: turn declared NAMES into invocables and instances. Guard, matcher,
 * extract and signal-handler methods bind to the workflow instance as closures with their shapes
 * proven; activities, compensations and fallbacks resolve from the activities container; a cadence
 * source becomes its typed resolver. Every refusal here is a constructive failure that holds its
 * own context.
 */
final readonly class WorkflowBinder
{
    public function __construct(
        private ContainerInterface $activities,
    ) {}

    /**
     * Resolve `$activityClass` from the activities container, refusing anything that is not an
     * `Activity`: the one construction failure every activity-carrying declaration shares.
     *
     * @throws InvalidWorkflowDefinition when the container cannot yield an `Activity` for the class
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function activity(string $activityClass, string $stateKey, string $workflow): Activity
    {
        $activity = $this->activities->get($activityClass);
        if (! $activity instanceof Activity) {
            throw InvalidWorkflowDefinition::unresolvableActivity($activityClass, $stateKey, $workflow);
        }

        return $activity;
    }

    /**
     * Bind a guard/matcher/extract method name to the workflow instance as a closure, or null when none.
     *
     * @param  ReflectionClass<object>  $class
     *
     * @throws ReflectionException
     */
    public function bind(ReflectionClass $class, ?string $method, object $instance, string $workflow): ?Closure
    {
        if ($method === null) {
            return null;
        }

        if (! $class->hasMethod($method)) {
            throw InvalidWorkflowDefinition::unknownMethod($method, $workflow);
        }

        return $class->getMethod($method)->getClosure($instance);
    }

    /**
     * Bind a `#[Signal]` handler to the workflow instance, proving its shape first: the executor invokes it
     * blind with the signal object and the vars, so a wrong arity or return type is refused at build rather
     * than exploding mid-step. The first parameter may be typed with the declared signal class itself or
     * any supertype; the lookup is exact-class and the engine never passes anything else, so a type that
     * cannot accept the declared signal is a cross-wiring such as `#[Signal(signal: A, handler: 'onB')]` and
     * is refused for the same reason.
     *
     * @param  ReflectionClass<object>  $class
     *
     * @throws InvalidWorkflowDefinition when the method is missing or its signature can't take the call
     * @throws ReflectionException
     */
    public function bindSignalHandler(ReflectionClass $class, SignalAttribute $signal, object $instance, string $workflow): Closure
    {
        if (! $class->hasMethod($signal->handler)) {
            throw InvalidWorkflowDefinition::unknownMethod($signal->handler, $workflow);
        }

        $method = $class->getMethod($signal->handler);
        $return = $method->getReturnType();

        // exactly two invocable arguments: fewer and the handler can't SEE the vars; its stay()
        // would wipe them; more required and the two-arg invocation can never satisfy it
        if (! $return instanceof ReflectionNamedType || $return->getName() !== SignalResult::class
            || $method->getNumberOfRequiredParameters() > 2 || $method->getNumberOfParameters() < 2) {
            throw InvalidWorkflowDefinition::signalHandlerBadSignature($signal->handler, $signal->signal, $workflow);
        }

        $first = $method->getParameters()[0]->getType();
        if ($first instanceof ReflectionNamedType && $first->getName() !== 'object'
            && ($first->isBuiltin() || ! is_a($signal->signal, $first->getName(), true))) {
            throw InvalidWorkflowDefinition::signalHandlerCannotAcceptSignal($signal->handler, $first->getName(), $signal->signal, $workflow);
        }

        return $method->getClosure($instance);
    }

    /**
     * Resolve the optional `#[WaitFor(extract:)]` into either a bound method closure for the string form,
     * `fn(object $event, array $vars): array`, the matcher's data-side twin, or a validated
     * `varName => payloadField` map for the array form. Exactly one is returned non-empty; both are empty
     * when no extract is declared. The string form's missing-method is caught by `bind()`.
     *
     * @param  ReflectionClass<object>  $class
     * @param  string|array<array-key, mixed>|null  $extract
     * @return array{0: Closure|null, 1: array<string, string>}
     *
     * @throws InvalidWorkflowDefinition when the map is empty or has a non-string/empty key or value
     * @throws ReflectionException
     */
    public function resolveExtract(ReflectionClass $class, string|array|null $extract, object $instance, string $stateKey, string $workflow): array
    {
        if ($extract === null) {
            return [null, []];
        }

        if (is_string($extract)) {
            return [$this->bind($class, $extract, $instance, $workflow), []];
        }

        if ($extract === []) {
            throw InvalidWorkflowDefinition::extractMapEmpty($stateKey, $workflow);
        }

        $map = [];
        foreach ($extract as $target => $field) {
            if (! is_string($target) || $target === '' || ! is_string($field) || $field === '') {
                throw InvalidWorkflowDefinition::extractMapMalformed($stateKey, $workflow);
            }
            $map[$target] = $field;
        }

        return [null, $map];
    }

    /**
     * Group the `#[Fallback]`s by state into a `FallbackPolicy` chain in declaration order, resolving each
     * to a `StaticFallback` or `ActivityFallback` and binding its optional domain guard.
     *
     * @param  ReflectionClass<object>  $class
     * @return array<string, FallbackPolicy>
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function fallbacksByState(ReflectionClass $class, object $instance, string $workflow): array
    {
        /** @var array<string, list<FallbackCandidate>> $byState */
        $byState = [];

        foreach ($class->getAttributes(Fallback::class) as $attribute) {
            /** @var Fallback $fallback */
            $fallback = $attribute->newInstance();
            $strategy = $this->resolveFallbackStrategy($fallback, $workflow);
            $byState[$fallback->state][] = new FallbackCandidate($strategy, $this->bind($class, $fallback->guard, $instance, $workflow));
        }

        return array_map(static fn (array $candidates): FallbackPolicy => new FallbackPolicy($candidates), $byState);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveFallbackStrategy(Fallback $fallback, string $workflow): FallbackStrategy
    {
        $hasActivity = $fallback->activity !== null;
        $hasVars = $fallback->vars !== null;

        if ($hasActivity && $hasVars) {
            throw InvalidWorkflowDefinition::fallbackTargetAmbiguous($fallback->state, $workflow);
        }
        if (! $hasActivity && ! $hasVars) {
            throw InvalidWorkflowDefinition::fallbackMissingTarget($fallback->state, $workflow);
        }

        if ($fallback->vars !== null) {
            return new StaticFallback($fallback->vars);
        }

        /** @var class-string $activityClass */
        $activityClass = $fallback->activity;

        return new ActivityFallback($this->activity($activityClass, $fallback->state, $workflow));
    }

    /**
     * Resolve the cadence source the declaration's exactly-one guard already proved coherent: a fixed
     * `Cadence`, a literal interval or a global literal cron built now, XOR a per-instance resolver, a
     * cron `fn(array $vars): string` for `cronVar` or `cronMethod`, an interval `fn(array $vars): int`
     * for `intervalVar` or `intervalMethod`, where the value is not known until the instance ticks, so
     * the cadence is built then from its vars via {@see ScheduleState::cadenceFor()}. A vars resolver
     * collapses a missing or mistyped value to the family's invalid sentinel, `''` or `0`, so the tick's
     * runtime guard reports it rather than a TypeError burying the state key.
     *
     * @param  ReflectionClass<object>  $class
     * @return array{0: Cadence|null, 1: (Closure(array<string, mixed>): string)|null, 2: (Closure(array<string, mixed>): int)|null} exactly one non-null
     *
     * @throws ReflectionException
     */
    public function resolveCadenceSource(ScheduleAttribute $schedule, object $instance, ReflectionClass $class, string $workflow): array
    {
        if ($schedule->intervalSeconds !== null) {
            return [new IntervalCadence($schedule->intervalSeconds), null, null];
        }
        if ($schedule->cron !== null) {
            return [new CronCadence($schedule->cron), null, null];
        }
        if ($schedule->cronVar !== null) {
            $key = $schedule->cronVar;

            return [null, static function (array $vars) use ($key): string {
                $value = $vars[$key] ?? null;

                return is_string($value) ? $value : '';
            }, null];
        }
        if ($schedule->intervalVar !== null) {
            $key = $schedule->intervalVar;

            return [null, null, static function (array $vars) use ($key): int {
                $value = $vars[$key] ?? null;

                return is_int($value) ? $value : 0;
            }];
        }
        if ($schedule->intervalMethod !== null) {
            // intervalMethod: the matcher-style bound method, wrapped to a typed int resolver. The (int)
            // cast is defensive, a no-op for a method honoring its int contract, so the CastInt mutant is
            // equivalent, the same family as the cron resolver's (string) below; left un-ignored so the
            // ternary's killed sibling mutants on this line stay tested.
            $bound = $this->bind($class, $schedule->intervalMethod, $instance, $workflow);

            // @infection-ignore-all; equivalent: the closure's own int return type coerces identically, so the cast states the contract twice
            return [null, null, $bound === null ? null : static fn (array $vars): int => (int) $bound($vars)];
        }

        // cronMethod: the matcher-style bound method, wrapped to a typed string resolver
        $bound = $this->bind($class, $schedule->cronMethod, $instance, $workflow);

        return [null, $bound === null ? null : static fn (array $vars): string => (string) $bound($vars), null];
    }

    /**
     * @param  array<string, Compensate>  $compensations  each stateKey to its `#[Compensate]`
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function resolveCompensation(array $compensations, string $stateKey, string $workflow): ?Activity
    {
        $compensate = $compensations[$stateKey] ?? null;
        if ($compensate === null) {
            return null;
        }

        if ($compensate->confirmedBy !== null && ! class_exists($compensate->confirmedBy) && ! interface_exists($compensate->confirmedBy)) {
            throw InvalidWorkflowDefinition::confirmedByUnknownClass($compensate->confirmedBy, $stateKey, $workflow);
        }

        return $this->activity($compensate->activity, $stateKey, $workflow);
    }
}
