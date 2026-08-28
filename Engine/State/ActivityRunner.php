<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Random\RandomException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\MissingBreakerResourceKey;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\BackoffStrategy;
use Storm\Saga\Workflow\FailureKind;
use Storm\Saga\Workflow\Fallback\FallbackStrategy;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\RetryPolicy;
use Throwable;

/**
 * Runs an activity state. A fired timer takes the `Timeout` transition. Otherwise, execute the
 * activity:
 *
 * - Success: the `Success` transition.
 *
 * - Failure: if the retry policy still allows it, with attempts left, a retryable error, and a kick
 *   that would land inside the visit's elapsed budget, stay and schedule a back-off kick; else the
 *   fallback if any, else the `Failure` transition.
 *
 * - Async: stay, arming the state's timeout, since the result arrives later as an event. An async
 *   state must declare a timeout; a missing deadline would let the saga wait forever, raising
 *   `MissingAsyncTimeout`.
 *
 * Any non-timeout wake, a delivered event included, executes the activity: the wake contract. That
 * is how an async stay completes, a wake-and-recheck with the job ref riding vars, and it is one
 * more re-run source on top of at-least-once redelivery, so an activity with a retry policy is
 * re-entrant by contract: its back-off spaces the engine's kicks, not external wakes. An event
 * landing mid-back-off re-runs now and burns the attempt; the fresh kick's upsert replaces the
 * pending one. The wake also re-arms an async stay's timeout, so a delivered event pushes the
 * deadline out.
 *
 * A state with a `#[CircuitBreaker]` is gated: while the breaker is open, the activity is not run,
 * with no retry, since re-hitting a downed resource is futile, and it goes straight to the fallback
 * or `Failure`. Otherwise, the outcome is recorded on the breaker; success closes it, failure may
 * trip it, except a failure carrying `retryAfterSeconds`, delivered backpressure with an
 * appointment, which is proof of life and records nothing.
 *
 * A state with a `#[Fallback]` chain gets a last chance before failing: on a terminal activity
 * failure or an open breaker, the applicable fallbacks run in order, and the first to succeed
 * salvages the step via its `Success` transition, carrying the fallback's vars and commands, else
 * the `Failure` transition. A fallback that runs because the breaker is open does not touch the
 * breaker, since the primary was not hit.
 *
 * A failure whose declared `FailureKind` is `Rejected` takes the honest `Failure` edge directly:
 * never retried whatever the filters say, never counted by the breaker, never salvaged by the
 * chain, since a business verdict is final. The same holds MID-CHAIN: a fallback that fails
 * `Rejected` stops the walk, so a later candidate cannot salvage what that rail just refused. An
 * unclassified failure keeps the standard retry, breaker, and fallback behavior.
 *
 * With no matching transition where one is needed, the saga halts.
 */
final readonly class ActivityRunner
{
    public function __construct(
        private TransitionSelector $selector,
        private ?CircuitBreaker $breaker = null,
    ) {}

    /**
     * Returns a verdict:
     *
     * - `Transition`: success, or a fallback salvage flagged degraded, a terminal failure taking its
     *   `Failure` edge, or a fired timer taking its `Timeout` edge.
     *
     * - `Stay`: a retryable failure, arming the back-off kick, or an async result, arming the deadline.
     *
     * - `Halt`: a trigger with no edge to take, such as a terminal failure and no `Failure` transition.
     *
     * @throws MissingAsyncTimeout when the activity goes async but the state declares no timeout
     * @throws MissingBreakerResourceKey when a per-instance breaker's resourceKey is absent from the run's vars
     * @throws ClockExceptionContract when a breaker cooldown instant cannot be derived
     * @throws Throwable when a driver failure is wrapped by the adapter
     */
    public function run(StateContext $ctx): Transition|Stay|Halt
    {
        $state = $ctx->state;
        if (! $state instanceof ActivityState) {
            return new Halt; // defensive: the machine dispatches by subtype
        }

        if ($ctx->stimulus->isTimeout()) {
            return $this->transitionOrHalt($state, OnTrigger::Timeout, $ctx->vars);
        }

        // Resolve the breaker's per-instance key from the run's input vars, a no-op for a shared breaker;
        // a #[CircuitBreaker(resourceKey:)] whose vars value is missing fails loud here, before the run.
        $service = $this->breaker; // a local, so the non-null narrowing survives the calls below
        $breaker = $state->circuitBreaker !== null && $service !== null
            ? $state->circuitBreaker->resolve($ctx->vars)
            : null;
        if ($breaker !== null && ! $service->allows($breaker)) {
            // breaker open, so skip the futile run and don't burn the retry budget; degrade if a fallback exists
            return $this->fallbackOrFail($state, $ctx, $ctx->vars);
        }

        $result = $this->execute($state, $ctx);

        if ($breaker !== null && $result->outcome === ActivityOutcome::Success) {
            $service->recordSuccess($breaker); // cheap: a conditional write, no lock on the healthy path
        }
        // Async is deferred work; its real result records later, not here

        $verdict = match ($result->outcome) {
            ActivityOutcome::Success => $this->transitionOrHalt($state, OnTrigger::Success, $result->vars, $result->commands),
            ActivityOutcome::Failure => $this->onFailure($state, $ctx, $result),
            ActivityOutcome::Async => $this->onAsync($state, $result, $ctx->workflowType),
        };

        if ($service !== null && $breaker !== null && $result->outcome === ActivityOutcome::Failure && $result->kind !== FailureKind::Rejected && $result->retryAfterSeconds === null) {
            // Recorded AFTER the failure was resolved by retry, fallback chain or Failure edge: the
            // write takes the breaker row's lock until the step commits, so it must NOT be held
            // THROUGH the synchronous fallback calls; under an outage, N workers would serialize on
            // the singleton row for the duration of every fallback, the same hot-row family as the
            // recordSuccess conditional write. The count is unchanged by that placement: every
            // primary INFRA failure records exactly once, salvaged or not, the primary DID fail.
            // `$breaker !== null` already implies the service; the re-test only carries the
            // narrowing across the match.
            $service->recordFailure($breaker);
        }

        return $verdict;
    }

    private function execute(ActivityState $state, StateContext $ctx): ActivityResult
    {
        $metadata = new Metadata($ctx->workflowType, $ctx->correlationId, $ctx->causationId, $ctx->enrichedContext);

        try {
            return $state->activity->run($ctx->vars, $metadata);
        } catch (Throwable $e) {
            // a thrown activity becomes a failure result, so the retry policy applies uniformly
            return ActivityResult::failure($e->getMessage(), $ctx->vars, $e);
        }
    }

    private function onFailure(ActivityState $state, StateContext $ctx, ActivityResult $result): Stay|Transition|Halt
    {
        $policy = $state->retry;
        $visit = $ctx->retries[$state->key] ?? null;
        $attempts = ($visit['n'] ?? 0) + 1;

        if ($policy !== null && $attempts <= $policy->maxAttempts && $this->withinRetryBudget($ctx) && $this->retryable($policy, $result)) {
            $delayMs = $this->delayFor($policy, $attempts, $result);
            // the visit window opens at the FIRST retryable failure; a pre-window row, whose entry
            // predates the window's existence, starts its clock here rather than being judged on a
            // past it never declared
            $since = $visit['since'] ?? $ctx->now->toString();

            if ($this->kickLandsInsideElapsedBudget($policy, $ctx->now, $since, $delayMs)) {
                $retries = $ctx->retries;
                $retries[$state->key] = ['n' => $attempts, 'since' => $since];

                return new Stay([TimerOp::armKick($state->key, $delayMs)], $result->vars, $retries, retryTotal: $ctx->retryTotal + 1);
            }
        }

        // a Rejected verdict never enters the chain: no fallback may salvage what a rail just
        // refused: the honest Failure edge, guards or no guards. Transient and unclassified keep
        // the chain, the soft-migration path.
        return $result->kind === FailureKind::Rejected
            ? $this->transitionOrHalt($state, OnTrigger::Failure, $result->vars)
            : $this->fallbackOrFail($state, $ctx, $result->vars);
    }

    /**
     * The next attempt's delay: the declared back-off, stretched by a downstream-requested delay when
     * the policy granted that right. The request can only LENGTHEN the back-off, capped at the grant:
     * a downstream saying "come back later" is heard, one saying "come back sooner" or "sleep a year"
     * is not, since hammering a throttled rail early is futile and an uncapped sleep would fake a dead
     * saga until the global cap, which does not preempt, finally fires.
     *
     * @return positive-int milliseconds
     */
    private function delayFor(RetryPolicy $policy, int $attempt, ActivityResult $result): int
    {
        $delayMs = $this->backoff($policy, $attempt);

        // Equivalent mutants on this condition, all three inert behind the `max`: the request can
        // only ever LENGTHEN, so a non-positive one, or an undeclared cap collapsing the `min` to
        // nothing, yields a floor the declared back-off already clears. The guard states the intent
        // rather than carrying it, and it stays for that.
        if ($policy->maxRequestedDelaySeconds !== null && $result->retryAfterSeconds !== null && $result->retryAfterSeconds > 0) {
            $delayMs = max($delayMs, min($result->retryAfterSeconds, $policy->maxRequestedDelaySeconds) * 1000);
        }

        return $delayMs;
    }

    /**
     * Whether the kick being armed would still land inside the visit's wall-clock budget. Judged on
     * the kick's DUE instant, mirroring the shell's rounding of a sub-second delay up to the 1s sweep
     * granularity, not on `now`: a retry whose wake could only be refused is refused up front, so no
     * timer row and no fence are burned on a sleep with a foregone conclusion.
     *
     * @param  string  $since  the visit window's opening instant, canonical PointInTime form
     *
     * @throws ClockExceptionContract when the stored window instant cannot be parsed
     */
    private function kickLandsInsideElapsedBudget(RetryPolicy $policy, PointInTime $now, string $since, int $delayMs): bool
    {
        if ($policy->maxElapsedSeconds === null) {
            return true;
        }

        // the ROUNDING is load-bearing and tested at the window edge; the `max(1, …)` around it is not,
        // a positive delay always ceiling to at least one second, so its own mutants are equivalent
        // @infection-ignore-all; equivalent: the floor of the max, per the line above
        $dueAt = $now->addSeconds(max(1, (int) ceil($delayMs / 1000)));

        // max(1, …) restates what the build rule already guarantees; addSeconds requires a positive int
        // @infection-ignore-all; equivalent: the floor of the max, per the line above
        return ! $dueAt->isAfter(PointInTime::from($since)->addSeconds(max(1, $policy->maxElapsedSeconds)));
    }

    /**
     * Whether this failure is worth retrying. The declared `FailureKind` speaks first: `Rejected` is
     * final whatever the filters say. Then the filters decide: a thrown failure matches on the
     * exception, by class, code, or message; a pure result-failure, `ActivityResult::failure('…')`
     * with no exception, matches on its error string. The declared policy applies either way: a
     * `doNotRetryOn: ['declined']` filter blocks a returned 'declined' error exactly like a thrown one.
     */
    private function retryable(RetryPolicy $policy, ActivityResult $result): bool
    {
        if ($result->kind === FailureKind::Rejected) {
            return false; // a business verdict is final: no filter can make it worth a retry
        }

        // Transient and unclassified fall to the declared filters: the kind names the category,
        // the strings refine within it; a specific transient can still be excluded
        if ($result->cause !== null) {
            return $policy->shouldRetry($result->cause);
        }

        return $policy->shouldRetryError($result->error ?? '');
    }

    /**
     * The workflow-level retry budget still has room: the instance's lifetime retry total,
     * `$ctx->retryTotal`, incremented on every retry and never reset, unlike the per-state `retries`
     * bag, is below `#[Workflow(retryBudget:)]`. Null means unlimited, so the per-state cap is the
     * only bound. When the budget is reached, a further per-state retry is denied and the activity
     * fails.
     */
    private function withinRetryBudget(StateContext $ctx): bool
    {
        $budget = $ctx->definition->retryBudget;

        return $budget === null || $ctx->retryTotal < $budget;
    }

    /**
     * Last chance before failing: walk the applicable fallback chain in order.
     *
     * @param  array<string, mixed>  $vars  the vars at the moment of failure, the base for each fallback
     */
    private function fallbackOrFail(ActivityState $state, StateContext $ctx, array $vars): Transition|Halt
    {
        $policy = $state->fallback;
        if ($policy !== null) {
            $metadata = new Metadata($ctx->workflowType, $ctx->correlationId, $ctx->causationId, $ctx->enrichedContext);
            foreach ($policy->candidatesFor($vars) as $strategy) {
                $result = $this->runFallback($strategy, $vars, $metadata);
                if ($result->outcome === ActivityOutcome::Success) {
                    // a fallback salvage becomes Success, but flagged degraded: its real effect is uncertain, so a
                    // later rollback won't blindly undo it
                    return $this->transitionOrHalt($state, OnTrigger::Success, $result->vars, $result->commands, degraded: true);
                }
                if ($result->outcome === ActivityOutcome::Failure && $result->kind === FailureKind::Rejected) {
                    // the chain-stop: a verdict rendered MID-CHAIN ends the walk; the next candidate
                    // may not salvage what this rail just refused. A thrown fallback stays unclassified,
                    // runFallback catching it kind-less, and keeps walking, like any transient.
                    return $this->transitionOrHalt($state, OnTrigger::Failure, $vars);
                }
                // not salvaged, transient/unclassified failure or async, so try the next fallback in the chain
            }
        }

        return $this->transitionOrHalt($state, OnTrigger::Failure, $vars);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function runFallback(FallbackStrategy $strategy, array $vars, Metadata $metadata): ActivityResult
    {
        try {
            return $strategy->execute($vars, $metadata);
        } catch (Throwable $e) {
            // a thrown fallback is just a non-salvage; move on to the next, or fail
            return ActivityResult::failure($e->getMessage(), $vars, $e);
        }
    }

    /**
     * @throws MissingAsyncTimeout when an async state declares no timeout; a missing deadline would let
     *                             the saga wait forever, so express "wait forever" as a long timeout
     */
    private function onAsync(ActivityState $state, ActivityResult $result, string $workflowType): Stay
    {
        if ($state->timeout === null) {
            throw MissingAsyncTimeout::forState($state->key, $workflowType);
        }

        return new Stay([TimerOp::armTimeout($state->key, $state->timeout->seconds)], $result->vars, commands: $result->commands);
    }

    /**
     * Delay before the next attempt: exponential `base * 2^(n-1)` or fixed, with optional jitter of
     * plus or minus 50%.
     *
     * @return positive-int milliseconds
     */
    private function backoff(RetryPolicy $policy, int $attempt): int
    {
        // removing the (int) cast below yields an equivalent mutant for CastInt: int*int is already int;
        // it only bites on overflow / absurd attempt counts.
        // Left unignored, so this statement's killed pow/subtraction mutants stay tested.
        $ms = $policy->strategy === BackoffStrategy::Fixed
            ? $policy->baseMs
            // @infection-ignore-all; equivalent: an int base times an integral power is an int, the attempt being one or more, so the cast narrows nothing
            : (int) ($policy->baseMs * (2 ** ($attempt - 1)));

        if ($policy->jitter) {
            try {
                $ms = random_int((int) floor($ms * 0.5), (int) ceil($ms * 1.5));
            } catch (RandomException) {
                // CSPRNG unavailable, astronomically rare, so keep the un-jittered delay
            }
        }

        return max(1, $ms);
    }

    /**
     * @param  array<string, mixed>  $vars
     * @param  list<object>  $commands  commands the activity asked to issue, success path only
     * @param  bool  $degraded  the transition is a fallback salvage, its effect uncertain
     */
    private function transitionOrHalt(ActivityState $state, OnTrigger $trigger, array $vars, array $commands = [], bool $degraded = false): Transition|Halt
    {
        $to = $this->selector->select($state, $trigger, $vars);

        return $to === null
            ? new Halt($commands)
            : new Transition($to, $trigger, $vars, $commands, $degraded);
    }
}
