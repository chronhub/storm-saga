<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\BackoffStrategy;
use Storm\Saga\Workflow\RetryPolicy;
use ValueError;

/**
 * A per-state retry policy for an activity: how many attempts, the back-off shape and base delay, and
 * optional include/exclude error filters, `doNotRetryOn` takes precedence. Built into a
 * `RetryPolicy` at discovery time.
 *
 * Two optional time dimensions on top of the attempt count:
 *
 * - `maxElapsedSeconds` bounds the VISIT in wall-clock time: once the window opened by the visit's
 *   first retryable failure closes, a further retry is refused even with attempts left, and refusal
 *   happens before arming the kick, so no timer sleeps only to be denied at wake. The window resets
 *   when the graph re-enters the state, like the attempt counter.
 *
 * - `maxRequestedDelaySeconds` grants the activity the right to stretch its own back-off, a
 *   downstream `Retry-After` carried by `ActivityResult::failure(retryAfterSeconds:)`. The grant IS
 *   the cap: a requested delay is honored up to this bound and can only lengthen the policy's
 *   back-off, never shorten it. Without the field, a requested delay is ignored.
 *
 * @see RetryPolicy
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Retry
{
    public BackoffStrategy $strategy;

    /**
     * @param  string|BackoffStrategy  $strategy  'exponential' | 'fixed'
     * @param  list<string>  $retryOn  error types/codes that SHOULD retry; empty retries all
     * @param  list<string>  $doNotRetryOn  error types/codes that must NOT retry, taking precedence
     * @param  int|null  $maxElapsedSeconds  wall-clock budget of one visit's retries; null caps by attempts only
     * @param  int|null  $maxRequestedDelaySeconds  honor an activity-requested delay up to this cap; null ignores requests
     *
     * @throws ValueError when `$strategy` is a string that is not a valid BackoffStrategy
     */
    public function __construct(
        public string $state,
        public int $maxAttempts = 0,
        string|BackoffStrategy $strategy = BackoffStrategy::Exponential,
        public int $baseMs = 500,
        public bool $jitter = true,
        public array $retryOn = [],
        public array $doNotRetryOn = [],
        public ?int $maxElapsedSeconds = null,
        public ?int $maxRequestedDelaySeconds = null,
    ) {
        $this->strategy = is_string($strategy) ? BackoffStrategy::from($strategy) : $strategy;
    }
}
