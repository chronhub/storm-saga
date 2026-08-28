<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * One saga per correlation, enforced by the schema through a unique index on
 * `workflow_instances.correlation_id`. A second saga TYPE claiming an already-owned correlation id is a
 * design conflict surfaced loudly at `create()`, instead of silently starving one of the two at outcome
 * routing, where the router resolves an instance from the correlation alone with `LIMIT 1`.
 * Deterministic; retrying cannot fix it.
 */
final class CorrelationAlreadyOwned extends RuntimeException implements SagaException
{
    public static function by(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'Correlation "%s" is already owned by another saga instance — one saga per correlation; workflow "%s" cannot claim it.',
            $correlationId,
            $workflowType,
        ));
    }
}
