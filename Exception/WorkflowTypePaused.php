<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A birth knocked on a workflow type an operator froze in `workflow_pauses`: refused RETRYABLE, the
 * `ParentNotBornYet` channel. A start riding the bus redelivers until the resume, or dead-letters at
 * the cap as honest evidence of a birth the window outlasted, where `redrive` is already the verb.
 * Never an acked skip: a start swallowed in silence would be a business intent vanishing into an
 * incident window.
 */
final class WorkflowTypePaused extends RuntimeException implements SagaException
{
    public static function forStart(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'Workflow type "%s" is paused by an operator (workflow_pauses): the start of "%s" is refused until storm:saga:resume — retryable, so a bus-borne start redelivers and dead-letters at its cap.',
            $workflowType,
            $correlationId,
        ));
    }
}
