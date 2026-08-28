<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Throwable;

/**
 * A per-activity retry policy: how many attempts and the back-off shape, plus optional error filters.
 * `shouldRetry()` decides whether a given failure is retryable; the actual delay computation lives with
 * the engine's retry handler using `$strategy`, `$baseMs`, and `$jitter`.
 *
 * Filter precedence: `doNotRetryOn` wins, an explicit exclusion failing fast; then, if `retryOn` is
 * non-empty, the error must match one of it; an empty `retryOn` means retry any failure. The filters apply
 * to both failure shapes: a thrown exception by class, code, or message, and a pure result-failure by its
 * error string, handled in `shouldRetryError()`.
 */
final readonly class RetryPolicy
{
    /**
     * @param  list<string>  $retryOn  error class/code/message substrings that SHOULD retry; empty retries all
     * @param  list<string>  $doNotRetryOn  error class/code/message substrings that must NOT retry, taking precedence
     * @param  int|null  $maxElapsedSeconds  wall-clock budget of one visit's retries, measured from the visit's
     *                                       first retryable failure; a retry whose kick would land past the window
     *                                       is refused up front. Null caps by attempts only.
     * @param  int|null  $maxRequestedDelaySeconds  the declared right to honor a delay the activity requested
     *                                              through `ActivityResult::failure(retryAfterSeconds:)`, and
     *                                              its cap: the honored delay can only lengthen the policy
     *                                              back-off, up to this bound. Null ignores requested delays.
     */
    public function __construct(
        public int $maxAttempts,
        public BackoffStrategy $strategy = BackoffStrategy::Exponential,
        public int $baseMs = 500,
        public bool $jitter = true,
        public array $retryOn = [],
        public array $doNotRetryOn = [],
        public ?int $maxElapsedSeconds = null,
        public ?int $maxRequestedDelaySeconds = null,
    ) {}

    public function shouldRetry(Throwable $error): bool
    {
        return $this->decide($error::class, $error->getMessage(), (string) $error->getCode());
    }

    /**
     * The filters for a non-thrown failure, an `ActivityResult::failure('…')` carrying an error string and
     * no exception: patterns match the message only. The declared policy applies to both failure shapes; a
     * returned rejection is as filterable as a thrown one.
     */
    public function shouldRetryError(string $error): bool
    {
        return $this->decide('', $error, '');
    }

    private function decide(string $class, string $message, string $code): bool
    {
        $matches = fn (string $pattern): bool => $this->matches($pattern, $class, $message, $code);

        // doNotRetryOn wins: an explicit exclusion fails fast
        if (array_any($this->doNotRetryOn, $matches)) {
            return false;
        }

        // an empty allowlist retries any failure; otherwise retry only an error the list matches
        return $this->retryOn === [] || array_any($this->retryOn, $matches);
    }

    /**
     * Match a pattern against the exact class or error code, or a case-insensitive substring of the class
     * name or the message; lenient matching so a policy can target a family of errors.
     */
    private function matches(string $pattern, string $class, string $message, string $code): bool
    {
        return $pattern === $class
            || $pattern === $code
            || stripos($class, $pattern) !== false
            || stripos($message, $pattern) !== false;
    }
}
