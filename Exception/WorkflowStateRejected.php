<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Throwable;

/**
 * The workflow's declared `validateState()` refused the bag at the write point, so the step's
 * transaction rolls back whole: no instance write, no timer, no outbox row; a shape the workflow
 * does not recognize never buys an effect. The refusal covers every writer equally: an activity's
 * result, a wait's extract, a fallback's salvage vars, and a signal handler all pass the same gate.
 *
 * The cause is the validator's own throw, preserved: the workflow says WHY in its own vocabulary.
 */
final class WorkflowStateRejected extends RuntimeException implements SagaException
{
    public static function at(string $workflowType, string $correlationId, string $stateKey, Throwable $cause): self
    {
        return new self(sprintf(
            'Workflow "%s" instance "%s" refused its state at "%s": %s',
            $workflowType, $correlationId, $stateKey, $cause->getMessage(),
        ), previous: $cause);
    }
}
