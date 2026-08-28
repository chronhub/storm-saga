<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A per-instance interval failed at the TICK. `#[Schedule(intervalVar:)]` or `intervalMethod` resolved
 * from the instance's vars to a non-positive number of seconds, a grid that cannot advance: a missing or
 * mistyped vars value, or a method computing zero. The build cannot validate a runtime-resolved interval,
 * whose value is only known at the tick, and only bounds-checks the literal `intervalSeconds`; this is
 * the runtime guard, the interval twin of {@see InvalidResolvedCron}.
 */
final class InvalidResolvedInterval extends RuntimeException implements SagaException
{
    public static function forState(string $stateKey, int $seconds): self
    {
        return new self(sprintf(
            'Schedule state "%s" resolved a per-instance interval to %d seconds; the interval must be positive.',
            $stateKey,
            $seconds,
        ));
    }
}
