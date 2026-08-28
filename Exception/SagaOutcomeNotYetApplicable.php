<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A delivered outcome event found its saga alive yet nothing consumed it: the event arrived ahead
 * of the wait that matches it, and acking it would lose it while the saga still runs toward that
 * wait. Deliberately a plain RuntimeException, retryable under Messenger's default policy, never
 * marked unrecoverable: the redelivery lands once the saga advances, the consumer dedup absorbs any
 * duplicate, and an event that never applies exhausts the transport's retries into the failure
 * transport, visible instead of silently dropped.
 *
 * Thrown only on the event-routing path `Engine::routeOutcome`; a foreign correlation and a settled
 * instance stay a quiet false, where a redelivery cannot help.
 */
final class SagaOutcomeNotYetApplicable extends RuntimeException implements SagaException
{
    public static function whileDelivering(string $workflowType, string $correlationId, string $eventClass): self
    {
        return new self(sprintf(
            'The saga %s/%s is not yet at a wait that consumes %s; the event arrived early and was not applied. Retry the delivery.',
            $workflowType, $correlationId, $eventClass,
        ));
    }
}
