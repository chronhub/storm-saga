<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * An optimistic-concurrency conflict: a step tried to persist a `workflow_instances` row whose
 * `version` had moved on, because a competing step won the race. Operational and recoverable, since the
 * caller reloads and retries, so `RuntimeException`, not a bug. Mirrors the Chronicler's
 * `ConcurrencyException` intent, kept Saga-local to preserve `Saga: ~`.
 */
final class StaleWorkflowInstance extends RuntimeException implements SagaException
{
    public static function forStep(string $workflowType, string $correlationId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrency conflict on saga "%s" / "%s": instance version is no longer %d.',
            $workflowType,
            $correlationId,
            $expectedVersion,
        ));
    }
}
