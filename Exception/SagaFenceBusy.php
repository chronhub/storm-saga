<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A fenced step found the saga's fence held by a concurrent step: the signal was NOT applied, and
 * acking it would lose it forever. The fence race was silent-by-false where the OCC race is
 * safe-by-throw; this exception removes the asymmetry. Deliberately a plain RuntimeException,
 * retryable under Messenger's default policy, never marked unrecoverable: the redelivery lands after the
 * concurrent step releases, and the stale-guard and consumer dedup absorb any duplicate.
 *
 * Thrown only on the consumer-safe entry points: `Engine::routeOutcome` for a delivered outcome event
 * and `Engine::startOrThrow` for a start message off the bus; timers never see it, their durable lease
 * IS their retry.
 */
final class SagaFenceBusy extends RuntimeException implements SagaException
{
    public static function whileDelivering(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'The fence for saga %s/%s is held by a concurrent step — the event was not applied; retry the delivery.',
            $workflowType, $correlationId,
        ));
    }

    public static function whileStarting(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'The fence for saga %s/%s is held by a concurrent step — the start was not applied; retry the start.',
            $workflowType, $correlationId,
        ));
    }
}
