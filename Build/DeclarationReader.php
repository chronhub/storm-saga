<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use ReflectionClass;
use Storm\Saga\Attributes\CircuitBreaker;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\ExposesState;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Schedule as ScheduleAttribute;
use Storm\Saga\Attributes\Signal as SignalAttribute;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Storm\Saga\Workflow\RetimePolicy;
use Storm\Saga\Workflow\RetryPolicy;

/**
 * The compile's reading phase: reflect a workflow class's attributes into grouped raw
 * declarations, one collector per attribute family, refusing a duplicate declaration where the
 * grouping could only keep one. Nothing is constructed and nothing is bound; the collectors hand
 * their maps to the declaration pass and the assembler.
 */
final readonly class DeclarationReader
{
    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, RetryPolicy>
     */
    public function retriesByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(Retry::class) as $attribute) {
            $retry = $attribute->newInstance();
            if (isset($map[$retry->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('Retry', $retry->state, $workflow);
            }
            $map[$retry->state] = new RetryPolicy(
                $retry->maxAttempts,
                $retry->strategy,
                $retry->baseMs,
                $retry->jitter,
                $retry->retryOn,
                $retry->doNotRetryOn,
                $retry->maxElapsedSeconds,
                $retry->maxRequestedDelaySeconds,
            );
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, WaitFor>
     */
    public function waitsByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(WaitFor::class) as $attribute) {
            $wait = $attribute->newInstance();
            if (isset($map[$wait->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('WaitFor', $wait->state, $workflow);
            }
            $map[$wait->state] = $wait;
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, ScheduleAttribute>
     */
    public function schedulesByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(ScheduleAttribute::class) as $attribute) {
            $schedule = $attribute->newInstance();
            if (isset($map[$schedule->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('Schedule', $schedule->state, $workflow);
            }
            $map[$schedule->state] = $schedule;
        }

        // @infection-ignore-all: one schedule state is the norm; the map-truncation mutant only diverges with 2+, mirroring the sibling *ByState collectors
        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, Compensate> each stateKey to its `#[Compensate]`, its activity and confirmedBy
     */
    public function compensationsByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(Compensate::class) as $attribute) {
            $compensate = $attribute->newInstance();
            if (isset($map[$compensate->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('Compensate', $compensate->state, $workflow);
            }
            $map[$compensate->state] = $compensate;
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, CircuitBreakerPolicy>
     */
    public function circuitBreakersByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(CircuitBreaker::class) as $attribute) {
            $breaker = $attribute->newInstance();
            if (isset($map[$breaker->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('CircuitBreaker', $breaker->state, $workflow);
            }
            if (trim($breaker->resource) === '') {
                throw InvalidWorkflowDefinition::circuitBreakerMissingResource($breaker->state, $workflow);
            }
            $map[$breaker->state] = new CircuitBreakerPolicy($breaker->resource, $breaker->failureThreshold, $breaker->cooldownSeconds, $breaker->resourceKey);
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, SignalAttribute> each signal class to its `#[Signal]` declaration
     */
    public function signalsByClass(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(SignalAttribute::class) as $attribute) {
            $signal = $attribute->newInstance();
            if (isset($map[$signal->signal])) {
                throw InvalidWorkflowDefinition::duplicateSignalHandler($signal->signal, $workflow);
            }
            $map[$signal->signal] = $signal;
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, RetimePolicy> each stateKey to its declared retime grant and caps
     */
    public function retimablesByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(Retimable::class) as $attribute) {
            $retimable = $attribute->newInstance();
            if (isset($map[$retimable->state])) {
                throw InvalidWorkflowDefinition::duplicateDecorator('Retimable', $retimable->state, $workflow);
            }
            $map[$retimable->state] = new RetimePolicy($retimable->maxRetimes, $retimable->maxExtensionSeconds);
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, list<RaceArm>> each stateKey to its declared arms, order preserved
     */
    public function raceArmsByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(RaceArm::class) as $attribute) {
            $arm = $attribute->newInstance();
            foreach ($map[$arm->state] ?? [] as $sibling) {
                if ($sibling->arm === $arm->arm) {
                    throw InvalidWorkflowDefinition::duplicateRaceArm($arm->arm, $arm->state, $workflow);
                }
            }
            $map[$arm->state][] = $arm;
        }

        return $map;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return array<string, list<JoinArm>> each stateKey to its declared arms, order preserved
     */
    public function joinArmsByState(ReflectionClass $class, string $workflow): array
    {
        $map = [];

        foreach ($class->getAttributes(JoinArm::class) as $attribute) {
            $arm = $attribute->newInstance();
            foreach ($map[$arm->state] ?? [] as $sibling) {
                if ($sibling->arm === $arm->arm) {
                    throw InvalidWorkflowDefinition::duplicateJoinArm($arm->arm, $arm->state, $workflow);
                }
            }
            $map[$arm->state][] = $arm;
        }

        return $map;
    }

    /**
     * The `#[ExposesState]` allowlist, checked at build: a blank key exposes nothing and can only be a
     * typo, and a duplicate means two declarations disagree about being one; both are loud, never
     * trimmed in silence, since this list is a SECURITY declaration.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    public function exposedStateKeys(ReflectionClass $class, string $workflow): array
    {
        $attribute = ($class->getAttributes(ExposesState::class)[0] ?? null)?->newInstance();
        if ($attribute === null) {
            return [];
        }

        $seen = [];
        foreach ($attribute->keys as $key) {
            if (trim($key) === '') {
                throw InvalidWorkflowDefinition::exposedStateKeyBlank($workflow);
            }
            if (isset($seen[$key])) {
                throw InvalidWorkflowDefinition::exposedStateKeyDuplicated($workflow, $key);
            }
            // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset above, so the value is arbitrary
            $seen[$key] = true;
        }

        return $attribute->keys;
    }
}
