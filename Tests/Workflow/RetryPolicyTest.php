<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Saga\Workflow\BackoffStrategy;
use Storm\Saga\Workflow\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function an_empty_allow_list_retries_any_failure(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);

        $this->assertSame(BackoffStrategy::Exponential, $policy->strategy); // default
        $this->assertSame(500, $policy->baseMs);                           // default
        $this->assertTrue($policy->jitter);                                // default
        $this->assertTrue($policy->shouldRetry(new RuntimeException('whatever')));
    }

    #[Test]
    public function do_not_retry_on_takes_precedence_even_over_the_allow_list(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, retryOn: [RuntimeException::class], doNotRetryOn: [RuntimeException::class]);

        $this->assertFalse($policy->shouldRetry(new RuntimeException('declined')));
    }

    #[Test]
    public function an_allow_list_only_retries_listed_errors(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, retryOn: [RuntimeException::class]);

        $this->assertTrue($policy->shouldRetry(new RuntimeException('transient')));
        $this->assertFalse($policy->shouldRetry(new LogicException('bug')));
    }

    #[Test]
    public function matches_by_code_and_by_message_substring(): void
    {
        $byCode = new RetryPolicy(maxAttempts: 3, retryOn: ['429']);
        $this->assertTrue($byCode->shouldRetry(new RuntimeException('too many', 429)));
        $this->assertFalse($byCode->shouldRetry(new RuntimeException('nope', 500)));

        $byMessage = new RetryPolicy(maxAttempts: 3, retryOn: ['rate limit']);
        $this->assertTrue($byMessage->shouldRetry(new RuntimeException('hit a RATE LIMIT'))); // case-insensitive
        $this->assertFalse($byMessage->shouldRetry(new RuntimeException('disk full')));
    }

    #[Test]
    public function the_filters_match_a_result_failure_on_its_error_string(): void
    {
        // a pure result-failure, an ActivityResult::failure('…') with nothing thrown, has no class and no code;
        // only its error string; the SAME declared patterns apply to it, message-substring-wise
        $policy = new RetryPolicy(maxAttempts: 3, retryOn: ['timeout'], doNotRetryOn: ['declined']);

        $this->assertTrue($policy->shouldRetryError('gateway TIMEOUT'));  // allowlisted, case-insensitive
        $this->assertFalse($policy->shouldRetryError('card declined'));   // excluded; precedence holds
        $this->assertFalse($policy->shouldRetryError('disk full'));       // not allowlisted

        $this->assertTrue(new RetryPolicy(maxAttempts: 3)->shouldRetryError('anything')); // no filters = retry any
    }
}
