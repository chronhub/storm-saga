<?php

declare(strict_types=1);

namespace Storm\Saga\Build\Rules;

use Cron\CronExpression;
use Exception;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Storm\Saga\Workflow\EffectGating;
use Storm\Saga\Workflow\RetimePolicy;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WaitState;

/**
 * The time family of the definition rules: wait deadlines, retries, retiming, schedules, and
 * every duration bound, declared and assembled alike.
 */
final readonly class TimeRules
{
    /**
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function globalTimeoutTargetExists(string $workflow, ?string $onGlobalTimeout, array $types): void
    {
        if ($onGlobalTimeout !== null && ! isset($types[$onGlobalTimeout])) {
            throw InvalidWorkflowDefinition::unknownGlobalTimeoutState($onGlobalTimeout, $workflow);
        }
    }

    /**
     * An `onGlobalTimeout` target is the state to drive when the global deadline fires; it needs a
     * `globalTimeout` to fire it. Declaring the target without the deadline is a dead config: the timer is
     * never armed, so the target is never reached. The reverse is fine; a `globalTimeout` without a target
     * is a bare cap, and at a gating wait it bounds the saga via HaltAtGlobalCap with no target required.
     *
     * @throws InvalidWorkflowDefinition when onGlobalTimeout is declared without a globalTimeout
     */
    public function globalTimeoutTargetHasADeadline(string $workflow, ?string $onGlobalTimeout, ?int $globalTimeout): void
    {
        if ($onGlobalTimeout !== null && $globalTimeout === null) {
            throw InvalidWorkflowDefinition::onGlobalTimeoutWithoutDeadline($onGlobalTimeout, $workflow);
        }
    }

    /**
     * A wait's deadline fields are coherent. A wait declares at most one deadline mode: `heartbeatSeconds`
     * escalates, `deadlineSeconds` finalizes on a wall-clock, or a business-time deadline via
     * `deadlineBusinessDays` / `deadlineBusinessHours`; they are mutually exclusive, since a wait pings or
     * expires once. A finalized deadline pairs with its target: a wall-clock or business deadline needs
     * `onDeadline`, since with no finalized edge the saga halts on expiry, and `onDeadline` needs a
     * deadline, since a target with no timer is dead config.
     *
     * @param  array<string, WaitFor>  $waits
     *
     * @throws InvalidWorkflowDefinition when a wait mixes deadline modes, or a deadline/target is unpaired
     */
    public function waitDeadlineFieldsAreCoherent(string $workflow, array $waits): void
    {
        foreach ($waits as $wait) {
            $hasBusinessDeadline = $wait->deadlineBusinessDays !== null || $wait->deadlineBusinessHours !== null;
            $hasDeadline = $wait->deadlineSeconds !== null || $hasBusinessDeadline;

            if ($wait->heartbeatSeconds !== null && $hasDeadline) {
                throw InvalidWorkflowDefinition::waitDeclaresBothHeartbeatAndDeadline($wait->state, $workflow);
            }
            if ($wait->deadlineSeconds !== null && $hasBusinessDeadline) {
                throw InvalidWorkflowDefinition::waitDeclaresWallAndBusinessDeadline($wait->state, $workflow);
            }
            if ($hasDeadline && $wait->onDeadline === null) {
                throw InvalidWorkflowDefinition::deadlineWithoutTarget($wait->state, $workflow);
            }
            if ($wait->onDeadline !== null && ! $hasDeadline) {
                throw InvalidWorkflowDefinition::onDeadlineWithoutDeadline($wait->state, $workflow);
            }
        }
    }

    /**
     * A wait's `onDeadline`, the finalized target the builder desugars into a timeout edge, must name a
     * declared state; the desugared transition is added after this pass, so it escapes the generic
     * transition-target.
     *
     * @param  array<string, WaitFor>  $waits
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition when onDeadline names an undeclared state
     */
    public function onDeadlineTargetsAKnownState(string $workflow, array $waits, array $types): void
    {
        foreach ($waits as $wait) {
            if ($wait->onDeadline !== null && ! isset($types[$wait->onDeadline])) {
                throw InvalidWorkflowDefinition::onDeadlineUnknownState($wait->onDeadline, $wait->state, $workflow);
            }
        }
    }

    /**
     * A `#[Schedule]` only means anything on a schedule state, the schedule-state twin of the WaitFor rule.
     *
     * @param  list<string>  $scheduleKeys
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition
     */
    public function scheduleForTargetsScheduleStates(string $workflow, array $scheduleKeys, array $types): void
    {
        foreach ($scheduleKeys as $stateKey) {
            if (($types[$stateKey] ?? null) !== StateType::Schedule) {
                throw InvalidWorkflowDefinition::scheduleOnNonScheduleState($stateKey, $workflow);
            }
        }
    }

    /**
     * A recurring workflow has no overall deadline: a `globalTimeout` would cap a saga meant to live
     * indefinitely, and every established saga library treats a recurring instance as a durable entity, not
     * a bounded run. Reject a `globalTimeout` on any workflow that declares a schedule state.
     *
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition when a schedule workflow declares a globalTimeout
     */
    public function scheduleWorkflowHasNoGlobalDeadline(string $workflow, array $types, ?int $globalTimeout): void
    {
        if ($globalTimeout === null) {
            return;
        }

        foreach ($types as $type) {
            if ($type === StateType::Schedule) {
                throw InvalidWorkflowDefinition::globalTimeoutOnScheduleWorkflow($workflow);
            }
        }
    }

    /**
     * A `#[Schedule]`'s cadence and catch-up fields are coherent: exactly one of the six cadence sources
     * `intervalSeconds`, `intervalVar`, `intervalMethod`, `cron`, `cronVar`, or `cronMethod`, never zero
     * and never two; a positive literal interval; a literal cron expression that parses; ReplayBounded
     * carries its `catchUpLimit`; a non-Skip catch-up asserts `idempotentTick`, since replaying the
     * period's work demands an idempotent tick the build cannot prove and the dev must declare; and a
     * declared spread is positive. A spread larger than the period is pointless but harmless, since the
     * grid translation is modulo the period, and the period is unknowable at build for a per-instance
     * source, so no upper bound is enforced. A per-instance source, `cronVar`, `cronMethod`,
     * `intervalVar` or `intervalMethod`, is not value-checked here: its value is only known at the tick,
     * so validity is a runtime guard raised as {@see InvalidResolvedCron} or `InvalidResolvedInterval`.
     *
     * @param  array<string, Schedule>  $schedules
     *
     * @throws InvalidWorkflowDefinition when a schedule's cadence or catch-up fields are incoherent
     */
    public function scheduleCadenceFieldsAreCoherent(string $workflow, array $schedules): void
    {
        foreach ($schedules as $schedule) {
            $sources = (int) ($schedule->intervalSeconds !== null)
                + (int) ($schedule->intervalVar !== null)
                + (int) ($schedule->intervalMethod !== null)
                + (int) ($schedule->cron !== null)
                + (int) ($schedule->cronVar !== null)
                + (int) ($schedule->cronMethod !== null);

            if ($sources !== 1) {
                throw $sources === 0
                    ? InvalidWorkflowDefinition::scheduleCadenceMissing($schedule->state, $workflow)
                    : InvalidWorkflowDefinition::scheduleCadenceAmbiguous($schedule->state, $workflow);
            }
            if ($schedule->intervalSeconds !== null && $schedule->intervalSeconds < 1) {
                throw InvalidWorkflowDefinition::scheduleIntervalNotPositive($schedule->state, $schedule->intervalSeconds, $workflow);
            }
            if ($schedule->cron !== null) {
                if (! CronExpression::isValidExpression($schedule->cron)) {
                    throw InvalidWorkflowDefinition::invalidCron($schedule->cron, $schedule->state, $workflow);
                }
                // parseable is not schedulable: '0 0 30 2 *' parses and never fires; prove ONE next
                // occurrence at build, or the poison surfaces as a rolled-back tick in production
                try {
                    new CronExpression($schedule->cron)->getNextRunDate();
                } catch (Exception) {
                    throw InvalidWorkflowDefinition::unsatisfiableCron($schedule->cron, $schedule->state, $workflow);
                }
            }
            if ($schedule->catchUp === CatchUp::ReplayBounded && $schedule->catchUpLimit === null) {
                throw InvalidWorkflowDefinition::catchUpLimitRequired($schedule->state, $workflow);
            }
            if ($schedule->catchUp !== CatchUp::Skip && ! $schedule->idempotentTick) {
                throw InvalidWorkflowDefinition::catchUpRequiresIdempotentTick($schedule->state, $workflow);
            }
            if ($schedule->spreadSeconds !== null && $schedule->spreadSeconds < 1) {
                throw InvalidWorkflowDefinition::scheduleSpreadNotPositive($schedule->state, $schedule->spreadSeconds, $workflow);
            }
        }
    }

    /**
     * Every declared duration is positive. A zero or negative timer is a config accident that either fires
     * immediately, as a `deadlineSeconds: 0` finalizes a business decision one second after the arm, or
     * floods, as a negative heartbeat escalates every sweep; the runtime floors wall-clock arms at 1s, but
     * the business-time path has no floor at all. Sibling of the schedule's own `intervalSeconds` guard.
     *
     * @param  list<StateAttribute>  $declared
     * @param  array<string, WaitFor>  $waits
     * @param  array<string, Schedule>  $schedules
     *
     * @throws InvalidWorkflowDefinition when a duration is zero or negative
     */
    public function durationsArePositive(string $workflow, array $declared, array $waits, ?int $globalTimeout, array $schedules): void
    {
        if ($globalTimeout !== null && $globalTimeout < 1) {
            throw InvalidWorkflowDefinition::durationNotPositive('#[Workflow] globalTimeout', $globalTimeout, $workflow, $workflow);
        }

        foreach ($declared as $state) {
            if ($state->timeoutSeconds !== null && $state->timeoutSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[State] timeoutSeconds', $state->timeoutSeconds, $state->key, $workflow);
            }
        }

        foreach ($waits as $wait) {
            if ($wait->heartbeatSeconds !== null && $wait->heartbeatSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[WaitFor] heartbeatSeconds', $wait->heartbeatSeconds, $wait->state, $workflow);
            }
            if ($wait->deadlineSeconds !== null && $wait->deadlineSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[WaitFor] deadlineSeconds', $wait->deadlineSeconds, $wait->state, $workflow);
            }
            if ($wait->deadlineBusinessDays !== null || $wait->deadlineBusinessHours !== null) {
                $days = $wait->deadlineBusinessDays ?? 0;
                $hours = $wait->deadlineBusinessHours ?? 0;
                if ($days < 0 || $hours < 0 || $days + $hours < 1) {
                    throw InvalidWorkflowDefinition::businessDeadlineZero($wait->state, $workflow);
                }
            }
        }

        foreach ($schedules as $schedule) {
            if ($schedule->catchUpLimit !== null && $schedule->catchUpLimit < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Schedule] catchUpLimit', $schedule->catchUpLimit, $schedule->state, $workflow);
            }
            if ($schedule->catchUpWindowSeconds !== null && $schedule->catchUpWindowSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Schedule] catchUpWindowSeconds', $schedule->catchUpWindowSeconds, $schedule->state, $workflow);
            }
        }
    }

    /**
     * A decorator that is present must be able to act. `#[Retry]` with `maxAttempts < 1` never retries
     * anything; `#[CircuitBreaker]` with a non-positive threshold never trips, and with a non-positive
     * cooldown admits every call while open; inert protection reads as protection that is not there, so
     * reject it, the sibling of `retryBudget >= 1`.
     *
     * Two `#[Retry]` values are inert while looking configured. A `baseMs < 1` floors to the engine's
     * minimum wait, so the attempts burn back to back with no backoff between them. A blank pattern in
     * either error list matches every class name and message, since patterns match by substring: in
     * `doNotRetryOn` it excludes EVERY error, and the state that declares attempts never retries once.
     *
     * @param  array<string, array<string, mixed>>  $decoratorsByLabel
     *
     * @throws InvalidWorkflowDefinition when a Retry or CircuitBreaker value cannot act
     */
    public function decoratorValuesAreCoherent(string $workflow, array $decoratorsByLabel): void
    {
        foreach ($decoratorsByLabel['Retry'] ?? [] as $stateKey => $policy) {
            if (! $policy instanceof RetryPolicy) {
                continue;
            }
            if ($policy->maxAttempts < 1) {
                throw InvalidWorkflowDefinition::retryAttemptsNotPositive((string) $stateKey, $policy->maxAttempts, $workflow);
            }
            if ($policy->baseMs < 1) {
                throw InvalidWorkflowDefinition::retryBaseDelayNotPositive((string) $stateKey, $policy->baseMs, $workflow);
            }
            foreach (['retryOn' => $policy->retryOn, 'doNotRetryOn' => $policy->doNotRetryOn] as $list => $patterns) {
                foreach ($patterns as $pattern) {
                    if (trim($pattern) === '') {
                        throw InvalidWorkflowDefinition::retryPatternBlank((string) $stateKey, $list, $workflow);
                    }
                }
            }
            if ($policy->maxElapsedSeconds !== null && $policy->maxElapsedSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Retry] maxElapsedSeconds', $policy->maxElapsedSeconds, (string) $stateKey, $workflow);
            }
            if ($policy->maxRequestedDelaySeconds !== null && $policy->maxRequestedDelaySeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Retry] maxRequestedDelaySeconds', $policy->maxRequestedDelaySeconds, (string) $stateKey, $workflow);
            }
        }

        foreach ($decoratorsByLabel['CircuitBreaker'] ?? [] as $stateKey => $policy) {
            if (! $policy instanceof CircuitBreakerPolicy) {
                continue;
            }
            if ($policy->failureThreshold < 1) {
                throw InvalidWorkflowDefinition::breakerFieldNotPositive('failureThreshold', $policy->failureThreshold, (string) $stateKey, $workflow);
            }
            if ($policy->cooldownSeconds < 1) {
                throw InvalidWorkflowDefinition::breakerFieldNotPositive('cooldownSeconds', $policy->cooldownSeconds, (string) $stateKey, $workflow);
            }
        }
    }

    /**
     * A `#[Retimable]` only means anything on a wait state, the retime twin of the WaitFor rule.
     *
     * @param  list<string>  $retimableKeys
     * @param  array<string, StateType>  $types
     *
     * @throws InvalidWorkflowDefinition when a Retimable targets a non-wait state
     */
    public function retimablesTargetWaitStates(string $workflow, array $retimableKeys, array $types): void
    {
        foreach ($retimableKeys as $stateKey) {
            if (($types[$stateKey] ?? null) !== StateType::Wait) {
                throw InvalidWorkflowDefinition::retimableOnNonWaitState($stateKey, $workflow);
            }
        }
    }

    /**
     * An uncapped retime dissolves the deadline it moves: chatter could push a business expiry
     * forever, the starvation `WaitRunner` refuses by doctrine. The grant must carry at least one
     * cap, and a declared cap must be able to act, the `maxAttempts >= 1` sibling.
     *
     * @param  array<string, RetimePolicy>  $retimables
     *
     * @throws InvalidWorkflowDefinition when a Retimable declares no cap, or a non-positive one
     */
    public function retimableCapsAreCoherent(string $workflow, array $retimables): void
    {
        foreach ($retimables as $stateKey => $policy) {
            if ($policy->maxRetimes === null && $policy->maxExtensionSeconds === null) {
                throw InvalidWorkflowDefinition::retimableWithoutACap((string) $stateKey, $workflow);
            }
            if ($policy->maxRetimes !== null && $policy->maxRetimes < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Retimable] maxRetimes', $policy->maxRetimes, (string) $stateKey, $workflow);
            }
            if ($policy->maxExtensionSeconds !== null && $policy->maxExtensionSeconds < 1) {
                throw InvalidWorkflowDefinition::durationNotPositive('#[Retimable] maxExtensionSeconds', $policy->maxExtensionSeconds, (string) $stateKey, $workflow);
            }
        }
    }

    /**
     * Reject a per-state `timeoutSeconds` at or above the workflow `globalTimeout`: the per-state timer
     * cannot achieve its purpose of acting before the global takes over. At `>` it never fires, since the
     * global preempts; at `=` the two race in the runner with non-deterministic ordering. Either way, the
     * per-state transition is silently dead-coded, so reject at build. With any global timeout, there is no
     * constraint.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a state's timeoutSeconds is at or above the workflow's global
     */
    public function perStateTimeoutsStayBelowGlobal(string $workflow, array $states, ?int $globalTimeout): void
    {
        if ($globalTimeout === null) {
            return;
        }

        foreach ($states as $state) {
            $timeout = match (true) {
                $state instanceof ActivityState, $state instanceof WaitState => $state->timeout,
                default => null,
            };

            if ($timeout !== null && $timeout->seconds >= $globalTimeout) {
                throw InvalidWorkflowDefinition::perStateTimeoutAtOrAboveGlobal(
                    $state->key, $timeout->seconds, $globalTimeout, $workflow,
                );
            }
        }
    }

    /**
     * Reject a `#[Retry] maxElapsedSeconds` at or above the workflow `globalTimeout`, the retry-budget twin
     * of `perStateTimeoutsStayBelowGlobal()`: the visit window cannot outlive the instance, so a budget the
     * global cap always beats is dead config that reads as a bound it never is.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a retry elapsed budget is at or above the workflow's global
     */
    public function retryElapsedBudgetsStayBelowGlobal(string $workflow, array $states, ?int $globalTimeout): void
    {
        if ($globalTimeout === null) {
            return;
        }

        foreach ($states as $state) {
            $elapsed = $state instanceof ActivityState ? $state->retry?->maxElapsedSeconds : null;

            if ($elapsed !== null && $elapsed >= $globalTimeout) {
                throw InvalidWorkflowDefinition::retryElapsedAtOrAboveGlobal($state->key, $elapsed, $globalTimeout, $workflow);
            }
        }
    }

    /**
     * A heartbeat wait, a timed wait with no finalizing timeout edge, must be effect-gating. A heartbeat
     * re-arms and escalates; on a non-gating wait the fired timer would route to the machine, find no
     * timeout edge, and silently halt. A timed wait with a timeout edge is a deadline, judged by the gating
     * guard instead; the pairing guard ran first, so by here a deadline always carries its desugared edge,
     * and a bare timed non-gating wait is therefore unambiguously a heartbeat.
     *
     * Rejecting a heartbeat on a non-gating wait deliberately scopes out the soft-SLA case, a liveness ping
     * on a wait awaiting an external event.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a heartbeat wait is not effect-gating
     */
    public function heartbeatWaitsMustGate(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof WaitState || $state->timeout === null || $this->hasTimeoutEdge($state)) {
                continue; // not a timed wait, or a deadline that carries its finalized edge
            }

            if (! EffectGating::gates($states, $state->key)) {
                throw InvalidWorkflowDefinition::heartbeatOnNonGatingWait($state->key, $workflow);
            }
        }
    }

    /**
     * A `retriable` wait must be a heartbeat wait, a timeout with no finalized edge: retriable tells the
     * engine to re-arm past the global cap instead of halting, which is only meaningful for a gating
     * heartbeat whose success is inevitable post-pivot. On a deadline wait, or a wait without
     * heartbeatSeconds, it would silently do nothing. Gating is then transitive, since
     * `heartbeatWaitsMustGate()` already rejects a heartbeat on a non-gating wait.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a retriable wait is not a heartbeat wait
     */
    public function retriableRequiresHeartbeat(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if ($state instanceof WaitState && $state->retriable
                && ($state->timeout === null || $this->hasTimeoutEdge($state))) {
                throw InvalidWorkflowDefinition::retriableWaitWithoutHeartbeat($state->key, $workflow);
            }
        }
    }

    /**
     * A retimable wait must own the deadline being moved: a timed wait with a finalizing timeout
     * edge. An effect-gating wait's deadline belongs to the engine, which re-arms and escalates,
     * the retime twin of `engineOwnsEffectGatingDeadlines()`; a heartbeat is liveness owned by the
     * escalator, not a business expiry, the twin of `heartbeatWaitsMustGate()`. Either grant would
     * hand the application a timer that was never the application's.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a Retimable wait gates an effect or has no finalizing deadline
     */
    public function retimedWaitsCarryTheirOwnDeadline(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof WaitState || $state->retime === null) {
                continue;
            }

            if (EffectGating::gates($states, $state->key)) {
                throw InvalidWorkflowDefinition::retimableOnGatingWait($state->key, $workflow);
            }
            // The second operand is unreachable from the attribute surface, an earlier rule already
            // refusing a deadline declared without its `onDeadline`, so a timed wait always carries
            // its finalizing edge by the time this runs. It stays as defense in depth for a
            // definition built by hand rather than from attributes.
            if ($state->timeout === null || ! $this->hasTimeoutEdge($state)) {
                throw InvalidWorkflowDefinition::retimableWithoutADeadline($state->key, $workflow);
            }
        }
    }

    /**
     * A schedule state must have an `#[On(trigger: 'schedule')]` edge: when a slot comes due, the tick takes
     * that transition, typically to an activity that does the period's work. Without it the fired cadence
     * has nowhere to go, and the runner would halt at every tick.
     *
     * @param  array<string, State>  $states
     *
     * @throws InvalidWorkflowDefinition when a schedule state has no schedule transition
     */
    public function scheduleStatesHaveAScheduleEdge(string $workflow, array $states): void
    {
        foreach ($states as $state) {
            if (! $state instanceof ScheduleState) {
                continue;
            }

            if (! $this->hasScheduleEdge($state)) {
                throw InvalidWorkflowDefinition::scheduleStateMissingScheduleEdge($state->key, $workflow);
            }
        }
    }

    private function hasTimeoutEdge(WaitState $wait): bool
    {
        return array_any($wait->transitions, fn ($transition) => $transition->trigger === OnTrigger::Timeout);
    }

    private function hasScheduleEdge(ScheduleState $state): bool
    {
        return array_any($state->transitions, fn ($transition) => $transition->trigger === OnTrigger::Schedule);
    }
}
