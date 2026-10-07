<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Contracts\Message\RetryableDelivery;

/**
 * A fenced step found the saga's fence held by a concurrent step: the signal was NOT applied, and
 * acking it would lose it forever. The fence race was silent-by-false where the OCC race is
 * safe-by-throw. The delivery marker requests a bounded retry on the batched lane; Messenger's
 * default policy retries this exception on the Worker lane. Redelivery lands after the
 * concurrent step releases, and the stale-guard and consumer dedup absorb any duplicate.
 *
 * Thrown on consumer-safe entry points, including child cancellations and family pokes.
 * Timers never see it; their durable lease is their retry.
 */
final class SagaFenceBusy extends RuntimeException implements RetryableDelivery, SagaException
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

    public static function whileCancelling(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'The fence for saga %s/%s is held by a concurrent step; the cancellation was not applied; retry the delivery.',
            $workflowType, $correlationId,
        ));
    }

    public static function whilePokingFamily(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'The fence for saga %s/%s is held by a concurrent step; the family poke was not applied; retry the delivery.',
            $workflowType, $correlationId,
        ));
    }
}
