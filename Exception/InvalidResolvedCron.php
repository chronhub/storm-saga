<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Throwable;

/**
 * A cron expression failed at the TICK. A per-instance cron, `#[Schedule(cronVar:)]` or `cronMethod`,
 * resolved from the instance's vars to an expression that does not parse, or a parseable one turned out
 * UNSATISFIABLE when walked: `0 0 30 2 *` never comes, since February 30th does not exist and the
 * library exhausts its iterations. The build cannot validate a runtime-resolved expression, whose value
 * is only known at the tick, and only parse-checks a literal one; this is the runtime guard, the twin of
 * the build's `InvalidWorkflowDefinition::invalidCron()` and `unsatisfiableCron()`.
 */
final class InvalidResolvedCron extends RuntimeException implements SagaException
{
    public static function forState(string $stateKey, string $expression, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Schedule state "%s" resolved a per-instance cron to "%s", which is not a valid cron expression.', $stateKey, $expression),
            previous: $previous,
        );
    }

    public static function unsatisfiable(string $expression, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Cron expression "%s" parses but can never be satisfied (the walk found no next occurrence — e.g. a Feb 30 date part). Fix the expression.', $expression),
            previous: $previous,
        );
    }
}
