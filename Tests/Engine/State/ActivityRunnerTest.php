<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\State;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\MissingBreakerResourceKey;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\ThrowingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\BackoffStrategy;
use Storm\Saga\Workflow\CircuitBreakerPolicy;
use Storm\Saga\Workflow\FailureKind;
use Storm\Saga\Workflow\Fallback\ActivityFallback;
use Storm\Saga\Workflow\Fallback\FallbackCandidate;
use Storm\Saga\Workflow\Fallback\FallbackPolicy;
use Storm\Saga\Workflow\Fallback\StaticFallback;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition as Edge;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class ActivityRunnerTest extends TestCase
{
    /** the ctx() clock instant; a visit window opened "now" carries this value */
    private const string NOW = '2026-01-01T00:00:00.000000+00:00';

    private ActivityRunner $runner;

    #[Override]
    protected function setUp(): void
    {
        $this->runner = new ActivityRunner(new TransitionSelector);
    }

    #[Test]
    public function success_takes_the_success_transition_with_the_new_vars(): void
    {
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::success(['ok' => true])), transitions: [new Edge(OnTrigger::Success, 'confirm')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('confirm', $verdict->to);
        $this->assertSame(OnTrigger::Success, $verdict->trigger);
        $this->assertSame(['ok' => true], $verdict->vars);
        $this->assertFalse($verdict->degraded); // a primary success is never degraded
    }

    #[Test]
    public function success_with_no_transition_halts(): void
    {
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::success()));

        $this->assertInstanceOf(Halt::class, $this->runner->run($this->ctx($state)));
    }

    #[Test]
    public function failure_without_a_retry_policy_takes_the_failure_transition(): void
    {
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('boom')), transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
    }

    #[Test]
    public function failure_within_the_retry_budget_stays_and_schedules_an_exponential_kick(): void
    {
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // attempt 1 yields delay 100·2^0 and opens the visit window at now
        $first = $this->runner->run($this->ctx($state));
        $this->assertInstanceOf(Stay::class, $first);
        $this->assertSame(['charge' => ['n' => 1, 'since' => self::NOW]], $first->retries);
        $this->assertSame(1, $first->retryTotal); // default ctx retryTotal 0 + this retry; pins the StateContext default
        $this->assertSame(TimerOpKind::ArmKick, $first->timerOps[0]->kind);
        $this->assertSame(100, $first->timerOps[0]->delayMs);

        // attempt 2, one already recorded, yields delay 100·2^1 and PRESERVES the window's opening
        $second = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 1, 'since' => self::NOW]]));
        $this->assertInstanceOf(Stay::class, $second);
        $this->assertSame(['charge' => ['n' => 2, 'since' => self::NOW]], $second->retries);
        $this->assertSame(200, $second->timerOps[0]->delayMs);
    }

    #[Test]
    public function a_fixed_backoff_uses_the_same_delay_each_attempt(): void
    {
        // the Exponential strategy is covered above, yielding 100, 200, …; Fixed keeps baseMs flat across attempts.
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, strategy: BackoffStrategy::Fixed, baseMs: 100, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $first = $this->runner->run($this->ctx($state));
        $this->assertInstanceOf(Stay::class, $first);
        $this->assertSame(100, $first->timerOps[0]->delayMs);

        $second = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 1, 'since' => self::NOW]]));
        $this->assertInstanceOf(Stay::class, $second);
        $this->assertSame(100, $second->timerOps[0]->delayMs); // fixed, NOT 200
    }

    #[Test]
    public function the_backoff_never_drops_below_a_one_millisecond_floor(): void
    {
        // a zero base, or jitter rounding down to 0, must still yield a positive delay; never a 0ms tight re-kick
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, baseMs: 0, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(1, $verdict->timerOps[0]->delayMs);
    }

    #[Test]
    public function failure_past_the_retry_budget_takes_the_failure_transition(): void
    {
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // 3 attempts already used, so attempt 4 > max, so no retry
        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 3, 'since' => self::NOW]]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function the_last_attempt_within_the_budget_still_retries(): void
    {
        // boundary: attempts == maxAttempts must STILL retry; a `<` here would stop one retry too early
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // 2 recorded, so this is attempt 3 == maxAttempts, still within budget, so stay, not the Failure transition
        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 2, 'since' => self::NOW]]));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(['charge' => ['n' => 3, 'since' => self::NOW]], $verdict->retries);
    }

    #[Test]
    public function the_workflow_retry_budget_caps_the_lifetime_retry_total(): void
    {
        // the per-state policy WOULD retry, attempt 1 <= maxAttempts 3, but the instance's LIFETIME total of 2
        // has reached the workflow budget of 2, so no retry: the activity takes its Failure edge
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state, retryBudget: 2, retryTotal: 2));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function a_failure_with_workflow_budget_left_retries_and_bumps_the_lifetime_total(): void
    {
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // lifetime total 1, budget 3, so there is room to retry, and the lifetime counter advances to 2
        $verdict = $this->runner->run($this->ctx($state, retryBudget: 3, retryTotal: 1));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(['charge' => ['n' => 1, 'since' => self::NOW]], $verdict->retries);
        $this->assertSame(2, $verdict->retryTotal); // the lifetime counter advanced past the per-state bag
    }

    #[Test]
    public function a_requested_delay_stretches_the_backoff_when_the_policy_grants_it(): void
    {
        // the downstream said "come back in 30s"; the policy's own back-off would kick in 100ms
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('429 too many requests', retryAfterSeconds: 30)),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false, maxRequestedDelaySeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(30_000, $verdict->timerOps[0]->delayMs); // the request was heard, not the 100ms base
    }

    #[Test]
    public function a_requested_delay_is_ignored_without_the_declared_grant(): void
    {
        // same request, but the policy never declared maxRequestedDelaySeconds: the capacity is
        // opt-in, so the back-off alone decides
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('429 too many requests', retryAfterSeconds: 30)),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(100, $verdict->timerOps[0]->delayMs);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_requested_delay_is_capped_at_the_declared_grant(): void
    {
        // a downstream asking for ten years must not put the saga to sleep: an uncapped honor would
        // fake a dead saga until the non-preempting global cap finally fires
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('503 maintenance', retryAfterSeconds: 315_360_000)),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false, maxRequestedDelaySeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(60_000, $verdict->timerOps[0]->delayMs); // clamped to the grant
    }

    #[Test]
    #[Group('adversarial')]
    public function a_requested_delay_never_shortens_the_declared_backoff(): void
    {
        // the downstream can only LENGTHEN: a "come back in 1s" against a 5s declared back-off is
        // hammering permission the policy never gave; and a non-positive request is noise, not a delay
        $policy = new RetryPolicy(maxAttempts: 3, strategy: BackoffStrategy::Fixed, baseMs: 5000, jitter: false, maxRequestedDelaySeconds: 60);

        $shorter = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('boom', retryAfterSeconds: 1)), $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        $verdict = $this->runner->run($this->ctx($shorter));
        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(5000, $verdict->timerOps[0]->delayMs);

        $noise = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('boom', retryAfterSeconds: 0)), $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        $verdict = $this->runner->run($this->ctx($noise));
        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(5000, $verdict->timerOps[0]->delayMs);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_shedding_failure_never_counts_toward_the_breaker(): void
    {
        // threshold 1: one counted failure opens it. The throttled reply carries an appointment,
        // delivered backpressure, proof of life, so it records NOTHING; the same failure without
        // the appointment counts as ordinary weather and trips it. A fleet under shed must never
        // open the breaker on a healthy rail.
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);

        $shed = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('429 too many requests', retryAfterSeconds: 30)), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($shed));
        $this->assertTrue($breaker->allows($policy)); // still closed: an appointment is not a failure

        $down = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('gateway timeout')), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($down));
        $this->assertFalse($breaker->allows($policy)); // ordinary weather still counts
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rejected_failure_ignores_its_requested_delay(): void
    {
        // a business verdict is final: a delay riding a Rejected failure must not buy it a retry,
        // or the finality hardening cracks through the side door
        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('declined', kind: FailureKind::Rejected, retryAfterSeconds: 30)),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false, maxRequestedDelaySeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refused')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refused', $verdict->to); // straight to Failure, the delay never heard
    }

    #[Test]
    public function a_retry_whose_kick_would_land_past_the_elapsed_budget_is_refused_up_front(): void
    {
        // window opened 61s ago, budget 60s: attempts remain (1 < 3) but the kick's due instant falls
        // past the window, so the refusal happens NOW, taking the Failure edge, with no timer armed
        // only to be denied at wake
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, strategy: BackoffStrategy::Fixed, baseMs: 1000, jitter: false, maxElapsedSeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 1, 'since' => '2025-12-31T23:58:59.000000Z']]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function a_kick_landing_exactly_at_the_window_edge_still_retries(): void
    {
        // boundary: due == window end must retry; `isAfter` and not `>=`, or the budget quietly
        // shrinks by one kick
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, strategy: BackoffStrategy::Fixed, baseMs: 1000, jitter: false, maxElapsedSeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // window opened 59s ago; due = now + 1s = window end exactly
        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 1, 'since' => '2025-12-31T23:59:01.000000Z']]));

        $this->assertInstanceOf(Stay::class, $verdict);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_sub_second_remainder_is_judged_at_the_second_it_will_actually_wake(): void
    {
        // The sweep has a one-second granularity, so a 1.2s delay wakes at 2s, not at 1.2s. The
        // budget is judged on that ROUNDED-UP instant, and the difference is a whole second: rounded
        // down, this kick reads as landing exactly on the window edge and is armed, then wakes past
        // it and is refused there, having burned a timer row and a fence for a foregone conclusion.
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 3, strategy: BackoffStrategy::Fixed, baseMs: 1200, jitter: false, maxElapsedSeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        // window opened 59s ago, so it ends at now + 1s; the kick is due at now + 2s
        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 1, 'since' => '2025-12-31T23:59:01.000000Z']]));

        $this->assertNotInstanceOf(Stay::class, $verdict);
    }

    #[Test]
    public function a_pre_window_row_opens_its_elapsed_clock_at_its_next_retry(): void
    {
        // a legacy row hydrates with a null `since`: it is not judged on a past it never declared;
        // the window opens here and persists with this retry
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom')),
            new RetryPolicy(maxAttempts: 5, strategy: BackoffStrategy::Fixed, baseMs: 1000, jitter: false, maxElapsedSeconds: 60),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state, retries: ['charge' => ['n' => 2, 'since' => null]]));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(['charge' => ['n' => 3, 'since' => self::NOW]], $verdict->retries);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_granted_requested_delay_still_bows_to_the_elapsed_budget(): void
    {
        // the PSP asks for 120s, the grant allows it, but the visit's budget is 60s: honoring the
        // request would arm a kick whose only possible outcome is refusal, so the saga fails now
        // rather than sleeping past its own patience
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('429 too many requests', retryAfterSeconds: 120)),
            new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false, maxElapsedSeconds: 60, maxRequestedDelaySeconds: 300),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function a_non_retryable_error_skips_retries_even_with_budget_left(): void
    {
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('boom', cause: new RuntimeException('nope'))),
            new RetryPolicy(maxAttempts: 3, jitter: false, doNotRetryOn: [RuntimeException::class]),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_result_failure_matching_do_not_retry_on_is_not_retried(): void
    {
        // a PURE result-failure, no exception thrown, carries only an error string; the declared
        // filters must bite on it exactly as on a thrown failure, or doNotRetryOn silently
        // half-applies: a returned 'card declined' would burn all 3 attempts before failing
        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('card declined')),
            new RetryPolicy(maxAttempts: 3, jitter: false, doNotRetryOn: ['declined']),
            transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to); // straight to Failure, no attempt burnt
    }

    #[Test]
    public function the_allow_list_applies_to_a_result_failure_error_string(): void
    {
        // the same policy, two result-failures: the listed error retries, the unlisted one fails fast
        $policy = new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false, retryOn: ['timeout']);

        $listed = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('gateway timeout')), $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        $this->assertInstanceOf(Stay::class, $this->runner->run($this->ctx($listed)));

        $unlisted = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('card declined')), $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);
        $verdict = $this->runner->run($this->ctx($unlisted));
        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function a_thrown_activity_becomes_a_failure(): void
    {
        $state = new ActivityState('charge', new ThrowingActivity(new RuntimeException('kaboom')), transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    public function an_async_result_stays_and_arms_the_timeout(): void
    {
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::async('job-1', ['issued' => true])), timeout: new Timeout(30));

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(['issued' => true], $verdict->vars);
        $this->assertSame(TimerOpKind::ArmTimeout, $verdict->timerOps[0]->kind);
        $this->assertSame(30, $verdict->timerOps[0]->seconds);
    }

    #[Test]
    public function an_async_result_without_a_timeout_fails_fast(): void
    {
        // an async state with no deadline would let the saga wait forever; a config bug, caught now
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::async('job-1', ['issued' => true])));

        $this->expectException(MissingAsyncTimeout::class);

        $this->runner->run($this->ctx($state));
    }

    #[Test]
    public function a_fired_timeout_transitions_without_running_the_activity(): void
    {
        $activity = new RecordingActivity(ActivityResult::success());
        $state = new ActivityState('charge', $activity, transitions: [new Edge(OnTrigger::Timeout, 'escalate')]);

        $verdict = $this->runner->run($this->ctx($state, isTimeout: true));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('escalate', $verdict->to);
        $this->assertSame(OnTrigger::Timeout, $verdict->trigger);
        $this->assertSame(0, $activity->calls); // the activity is not executed on a timeout
    }

    #[Test]
    public function an_open_circuit_breaker_fast_fails_without_running_the_activity(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);
        $breaker->recordFailure($policy); // trip it open

        $activity = new RecordingActivity(ActivityResult::success());
        $state = new ActivityState('charge', $activity, circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);              // straight to the failure transition
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
        $this->assertSame(0, $activity->calls);                 // and the activity is never run
    }

    #[Test]
    public function a_succeeding_activity_records_a_success_on_its_breaker(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 2, cooldownSeconds: 60);
        $breaker->recordFailure($policy); // one failure on the books, below the threshold, still closed

        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::success(['ok' => true])), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Success, 'confirm')]);

        $verdict = new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('confirm', $verdict->to); // ran and succeeded

        // the success reset the breaker's failure count, so another single failure does NOT trip it;
        // without the reset, this would be the 2nd failure and the breaker would open.
        $breaker->recordFailure($policy);
        $this->assertTrue($breaker->allows($policy));
    }

    #[Test]
    public function a_failing_activity_records_a_failure_on_its_breaker(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);

        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('boom')), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state)); // fails, then recordFailure, then trips

        $this->assertFalse($breaker->allows($policy)); // the breaker is now open
    }

    #[Test]
    public function a_failure_on_a_state_without_a_breaker_policy_records_nothing(): void
    {
        // the runner HAS a breaker service but the STATE declares no #[CircuitBreaker]; a failure has
        // nothing resolved to record against; recording anyway would crash, nothing to record ON
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::failure('boom')), transitions: [new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refund', $verdict->to);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_fallback_salvage_still_counts_the_primary_failure_on_the_breaker(): void
    {
        // the failure is recorded AFTER the fallback chain resolved the verdict; the breaker row's lock
        // must not be held through the synchronous fallbacks. But moving it must not change the COUNT:
        // the primary DID fail, salvaged or not, or the breaker never learns about a permanently-degraded
        // dependency kept alive by its fallback
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);

        $state = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::failure('primary down')),
            circuitBreaker: $policy,
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['degraded' => true]))]),
            transitions: [new Edge(OnTrigger::Success, 'confirm'), new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('confirm', $verdict->to);    // the step WAS salvaged...
        $this->assertTrue($verdict->degraded);
        $this->assertFalse($breaker->allows($policy)); // ...and the primary failure still tripped the breaker
    }

    #[Test]
    public function a_per_instance_breaker_trips_one_instance_and_leaves_a_healthy_one_admitting(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $runner = new ActivityRunner(new TransitionSelector, $breaker);

        // one breaker PER atm device, keyed on the saga's atmId var
        $policy = new CircuitBreakerPolicy('atm-dispense', failureThreshold: 1, cooldownSeconds: 60, resourceKey: 'atmId');

        // atm-17 jams once, so its breaker, and only its, trips at threshold 1
        $failing = new ActivityState('dispense', new RecordingActivity(ActivityResult::failure('jam')), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'release')]);
        $runner->run($this->ctx($failing, vars: ['atmId' => 'atm-17']));

        // atm-17 again: its breaker is open, so fast-fail, the activity is NOT run
        $atm17 = new RecordingActivity(ActivityResult::success());
        $runner->run($this->ctx(new ActivityState('dispense', $atm17, circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Failure, 'release'), new Edge(OnTrigger::Success, 'settle')]), vars: ['atmId' => 'atm-17']));
        $this->assertSame(0, $atm17->calls); // jammed device is short-circuited

        // atm-42, a healthy device, has its OWN breaker, untouched, so it still runs and succeeds
        $atm42 = new RecordingActivity(ActivityResult::success(['ok' => true]));
        $verdict = $runner->run($this->ctx(new ActivityState('dispense', $atm42, circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Success, 'settle')]), vars: ['atmId' => 'atm-42']));
        $this->assertSame(1, $atm42->calls);                   // healthy device runs, not fast-failed by atm-17's jam
        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('settle', $verdict->to);
    }

    #[Test]
    public function a_per_instance_breaker_with_no_key_in_vars_fails_loud(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('atm-dispense', failureThreshold: 1, cooldownSeconds: 60, resourceKey: 'atmId');
        $state = new ActivityState('dispense', new RecordingActivity(ActivityResult::success()), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Success, 'settle')]);

        $this->expectException(MissingBreakerResourceKey::class); // never silently collapse to the shared key

        new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state, vars: ['somethingElse' => 'x']));
    }

    #[Test]
    public function a_breaker_policy_with_no_wired_service_runs_the_activity_normally(): void
    {
        // doubly-guarded: a declared CB policy with no breaker service wired must degrade to "just run it",
        // never dereference a null breaker; both gates check $this->breaker !== null
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);
        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::success(['ok' => true])), circuitBreaker: $policy, transitions: [new Edge(OnTrigger::Success, 'confirm')]);

        // $this->runner has NO CircuitBreaker service injected
        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('confirm', $verdict->to);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rejected_failure_never_retries_whatever_the_filters_say(): void
    {
        // maxAttempts 3 and EMPTY filters, the retry-everything policy, yet the kind is final:
        // a business verdict re-run would re-ask a question the domain already answered
        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('declined: insufficient_funds', kind: FailureKind::Rejected)),
            new RetryPolicy(maxAttempts: 3, jitter: false),
            transitions: [new Edge(OnTrigger::Failure, 'refused')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refused', $verdict->to); // straight to Failure, zero attempts burnt
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rejected_failure_skips_the_fallback_chain(): void
    {
        // the founding scenario of the kind: an applicable, guard-free fallback stands ready to
        // salvage, and must NOT, because the primary's failure IS a verdict. Without the kind, only
        // the discipline of guarding every candidate on verdict-vars prevents a stand-in from
        // approving what a rail just refused.
        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('declined: insufficient_funds', kind: FailureKind::Rejected)),
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['authorization' => 'stand_in']))]),
            transitions: [new Edge(OnTrigger::Success, 'captured'), new Edge(OnTrigger::Failure, 'refused')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refused', $verdict->to);            // the honest edge, not the salvage
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
        $this->assertArrayNotHasKey('authorization', $verdict->vars); // nothing was merged
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rejected_failure_records_nothing_on_the_breaker(): void
    {
        // threshold 1: a single counted failure would open it; a refusal is not a rail failure,
        // and counting it would trip the breaker on perfectly healthy weather
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);

        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('declined', kind: FailureKind::Rejected)),
            circuitBreaker: $policy,
            transitions: [new Edge(OnTrigger::Failure, 'refused')]);

        new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertTrue($breaker->allows($policy)); // still closed: the verdict never counted
    }

    #[Test]
    public function a_transient_failure_retries_and_the_filters_still_refine_within_it(): void
    {
        // the kind names the category, the strings refine within it: the same Transient failure
        // retries under an empty policy and is excluded by a matching doNotRetryOn
        $retryAll = new RetryPolicy(maxAttempts: 3, baseMs: 100, jitter: false);
        $open = new ActivityState('call', new RecordingActivity(ActivityResult::failure('gateway timeout', kind: FailureKind::Transient)), $retryAll, transitions: [new Edge(OnTrigger::Failure, 'abort')]);
        $this->assertInstanceOf(Stay::class, $this->runner->run($this->ctx($open)));

        $excluded = new RetryPolicy(maxAttempts: 3, jitter: false, doNotRetryOn: ['timeout']);
        $filtered = new ActivityState('call', new RecordingActivity(ActivityResult::failure('gateway timeout', kind: FailureKind::Transient)), $excluded, transitions: [new Edge(OnTrigger::Failure, 'abort')]);
        $verdict = $this->runner->run($this->ctx($filtered));
        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('abort', $verdict->to);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_rejected_verdict_mid_chain_stops_the_walk(): void
    {
        // THE founding chain-stop scenario: the secondary acquirer DECLINES, a delivered verdict,
        // while a guard-free stand-in waits next in the chain, ready to approve what a rail just
        // refused. Without the kind, the walk treats any fallback failure as "try the next one".
        $standIn = new RecordingActivity(ActivityResult::success(['authorization' => 'stand_in']));
        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('primary down', kind: FailureKind::Transient)),
            fallback: new FallbackPolicy([
                new FallbackCandidate(new ActivityFallback(new RecordingActivity(ActivityResult::failure('declined: secondary', kind: FailureKind::Rejected)))),
                new FallbackCandidate(new ActivityFallback($standIn)),
            ]),
            transitions: [new Edge(OnTrigger::Success, 'captured'), new Edge(OnTrigger::Failure, 'refused')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('refused', $verdict->to);             // the verdict ended the walk
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
        $this->assertSame(0, $standIn->calls);                  // the stand-in NEVER ran
    }

    #[Test]
    public function a_transient_failure_mid_chain_keeps_walking(): void
    {
        // the contrast: weather mid-chain is still just weather; the walk continues to the next
        // candidate, exactly the behavior an unclassified failure also keeps
        $state = new ActivityState(
            'authorize',
            new RecordingActivity(ActivityResult::failure('primary down', kind: FailureKind::Transient)),
            fallback: new FallbackPolicy([
                new FallbackCandidate(new ActivityFallback(new RecordingActivity(ActivityResult::failure('secondary timeout', kind: FailureKind::Transient)))),
                new FallbackCandidate(new StaticFallback(['route' => 'cached'])),
            ]),
            transitions: [new Edge(OnTrigger::Success, 'next'), new Edge(OnTrigger::Failure, 'abort')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('next', $verdict->to);
        $this->assertTrue($verdict->degraded);
        $this->assertSame(['route' => 'cached'], $verdict->vars);
    }

    #[Test]
    public function a_transient_failure_still_reaches_the_fallback_chain(): void
    {
        // the contrast that makes the Rejected skip meaningful: weather stays salvageable
        $state = new ActivityState(
            'call',
            new RecordingActivity(ActivityResult::failure('gateway timeout', kind: FailureKind::Transient)),
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['route' => 'backup']))]),
            transitions: [new Edge(OnTrigger::Success, 'next'), new Edge(OnTrigger::Failure, 'abort')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('next', $verdict->to);
        $this->assertTrue($verdict->degraded);
    }

    #[Test]
    public function a_terminal_failure_is_salvaged_by_a_static_fallback(): void
    {
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('pricing service down')),
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['price' => 100]))]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('charge', $verdict->to);              // salvaged, so the forward path, not 'abort'
        $this->assertSame(OnTrigger::Success, $verdict->trigger);
        $this->assertSame(['price' => 100], $verdict->vars);
        $this->assertTrue($verdict->degraded); // a fallback salvage is flagged degraded; its real effect is uncertain
    }

    #[Test]
    public function an_activity_fallback_runs_an_alternative_activity(): void
    {
        $secondary = new RecordingActivity(ActivityResult::success(['price' => 90]));
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('primary down')),
            fallback: new FallbackPolicy([new FallbackCandidate(new ActivityFallback($secondary))]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('charge', $verdict->to);
        $this->assertSame(['price' => 90], $verdict->vars);
        $this->assertSame(1, $secondary->calls);                // the alternative activity ran
    }

    #[Test]
    public function the_fallback_chain_tries_in_order_until_one_succeeds(): void
    {
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('primary down')),
            fallback: new FallbackPolicy([
                new FallbackCandidate(new ActivityFallback(new RecordingActivity(ActivityResult::failure('secondary down')))),
                new FallbackCandidate(new StaticFallback(['price' => 50])),
            ]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')],
        );

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('charge', $verdict->to);              // first fallback failed, second salvaged
        $this->assertSame(['price' => 50], $verdict->vars);
    }

    #[Test]
    public function a_fallback_whose_guard_fails_is_skipped(): void
    {
        // a domain guard (fn(vars): bool) gates the fallback; false skips it, no salvage, so Failure
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('primary down')),
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['price' => 1]), guard: static fn (array $vars): bool => false)]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')]);

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('abort', $verdict->to);
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
    }

    #[Test]
    public function a_fallback_that_also_fails_takes_the_failure_transition(): void
    {
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('primary down')),
            fallback: new FallbackPolicy([new FallbackCandidate(new ActivityFallback(new RecordingActivity(ActivityResult::failure('also down'))))]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')],
        );

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('abort', $verdict->to);
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
    }

    #[Test]
    public function a_fallback_that_throws_is_treated_as_a_non_salvage(): void
    {
        // distinct from a fallback that returns a failure: one that THROWS is caught and treated as a
        // non-salvage too; the chain moves on, here with no further candidate, to the Failure transition.
        $state = new ActivityState(
            'price',
            new RecordingActivity(ActivityResult::failure('primary down')),
            fallback: new FallbackPolicy([new FallbackCandidate(new ActivityFallback(new ThrowingActivity(new RuntimeException('fallback kaboom'))))]),
            transitions: [new Edge(OnTrigger::Success, 'charge'), new Edge(OnTrigger::Failure, 'abort')],
        );

        $verdict = $this->runner->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('abort', $verdict->to);
        $this->assertSame(OnTrigger::Failure, $verdict->trigger);
    }

    #[Test]
    public function an_open_breaker_degrades_to_the_fallback_instead_of_failing(): void
    {
        $clock = new MutableClock;
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStorage($clock), $clock);
        $policy = new CircuitBreakerPolicy('payment-gateway', failureThreshold: 1, cooldownSeconds: 60);
        $breaker->recordFailure($policy); // trip it open

        $primary = new RecordingActivity(ActivityResult::success()); // would succeed, but the breaker is open
        $state = new ActivityState(
            'charge',
            $primary,
            circuitBreaker: $policy,
            fallback: new FallbackPolicy([new FallbackCandidate(new StaticFallback(['degraded' => true]))]),
            transitions: [new Edge(OnTrigger::Success, 'confirm'), new Edge(OnTrigger::Failure, 'refund')]);

        $verdict = new ActivityRunner(new TransitionSelector, $breaker)->run($this->ctx($state));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('confirm', $verdict->to);             // degraded forward, not 'refund'
        $this->assertSame(['degraded' => true], $verdict->vars);
        $this->assertSame(0, $primary->calls);                  // the primary never ran; the breaker was open
    }

    #[Test]
    #[Group('adversarial')]
    public function a_wrong_state_subtype_halts_defensively(): void
    {
        // The MachineRunner dispatches by subtype before calling a runner directly; calling ActivityRunner
        // with a WaitState proves the guard returns Halt instead of dereferencing the wrong subtype.
        $state = new WaitState('wrong', eventClasses: []);
        $ctx = new StateContext(
            'wf',
            'corr-1',
            new WorkflowDefinition('wf', [$state->key => $state], $state->key),
            $state,
            Stimulus::none(),
            PointInTime::from('2026-01-01T00:00:00.000000Z'),
            anchor: PointInTime::from('2026-01-01T00:00:00.000000Z'),
        );

        $this->assertInstanceOf(Halt::class, $this->runner->run($ctx));
    }

    /**
     * @param  array<string, array{n: int, since: string|null}>  $retries
     * @param  array<string, mixed>  $vars
     */
    private function ctx(ActivityState $state, array $retries = [], bool $isTimeout = false, ?int $retryBudget = null, int $retryTotal = 0, array $vars = []): StateContext
    {
        return new StateContext(
            'wf',
            'corr-1',
            new WorkflowDefinition('wf', [$state->key => $state], $state->key, retryBudget: $retryBudget),
            $state,
            $isTimeout ? Stimulus::timeout() : Stimulus::none(),
            PointInTime::from(self::NOW),
            anchor: PointInTime::from(self::NOW),
            vars: $vars,
            retries: $retries,
            retryTotal: $retryTotal,
        );
    }
}
