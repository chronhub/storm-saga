<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Contracts\Message\SerializablePayload;
use Throwable;

/**
 * The immutable verdict an `Activity` returns. Built only through the named factories so the outcome
 * combinations stay valid: `success` carries the updated `vars`; `failure` carries the error and an
 * optional cause; `async` carries the id of the work dispatched elsewhere, after which the saga waits for
 * the resulting event.
 *
 * `success` and `async` may also carry `commands` to issue: the engine writes them to the saga outbox in
 * the step's transaction, atomic with the state advance, and a relay dispatches them to the command bus
 * after commit. The activity stays pure, returning intent while the step does the I/O, and each command
 * must be a `SerializablePayload` so it can be stored durably.
 *
 * @see Activity
 * @see \Storm\Contracts\Message\SerializablePayload
 */
final readonly class ActivityResult
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  list<object>  $commands  commands to issue durably, on success or async only
     * @param  int|null  $retryAfterSeconds  the downstream-requested next-attempt delay, failure only
     */
    private function __construct(
        public ActivityOutcome $outcome,
        public array $vars = [],
        public ?string $error = null,
        public ?string $asyncId = null,
        public ?Throwable $cause = null,
        public array $commands = [],
        public ?FailureKind $kind = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $vars  the new state bag after the activity ran
     * @param  list<object>  $commands  commands to issue durably as part of this step
     */
    public static function success(array $vars = [], array $commands = []): self
    {
        return new self(ActivityOutcome::Success, $vars, commands: $commands);
    }

    /**
     * `$kind` is the activity's DOMAIN classification of this failure, declared at the catch site;
     * a thrown activity is always unclassified, since the engine never derives domain from an
     * exception class, and an activity that wants a kind catches and returns instead of letting it
     * bubble. Null leaves the failure unclassified end to end.
     *
     * `$retryAfterSeconds` relays downstream backpressure, a `429`/`503 Retry-After` read at the
     * catch site: when to come back, not whether to. The policy decides whether it is heard at all;
     * only a state declaring `#[Retry(maxRequestedDelaySeconds:)]` honors it, capped there, and the
     * honored value can only lengthen the declared back-off, never shorten it. It is advisory on a
     * `Rejected` failure, which never retries whatever the delay says. A failure carrying it is
     * NEVER counted by the circuit breaker: an appointment is proof of life, and counting shedding
     * would open the breaker on a healthy rail.
     *
     * @param  array<string, mixed>  $vars
     *
     * @see RetryPolicy::$maxRequestedDelaySeconds
     */
    public static function failure(string $error, array $vars = [], ?Throwable $cause = null, ?FailureKind $kind = null, ?int $retryAfterSeconds = null): self
    {
        return new self(ActivityOutcome::Failure, $vars, $error, null, $cause, kind: $kind, retryAfterSeconds: $retryAfterSeconds);
    }

    /**
     * `$asyncId` is purely informative, a log-friendly name for the deferred work: the engine never
     * correlates the outcome by it. The wake contract, invariant 9, re-runs the resting activity, which
     * re-checks by whatever reference it put in `vars`. If your activity needs the id later, put it in
     * `$vars`.
     *
     * @param  array<string, mixed>  $vars
     * @param  list<object>  $commands  commands to issue durably, such as the work being dispatched
     */
    public static function async(string $asyncId, array $vars = [], array $commands = []): self
    {
        return new self(ActivityOutcome::Async, $vars, null, $asyncId, commands: $commands);
    }
}
