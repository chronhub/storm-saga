<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A single drive made more transitions than the workflow has states plus a one-hop margin: it is
 * looping through transitions without ever reaching a blocking state of wait, stay or final. That is a
 * definition bug, a cycle with no pause, caught fast so a runaway step can't spin forever holding the
 * fence.
 */
final class WorkflowStepLimitExceeded extends LogicException implements SagaException
{
    public static function inWorkflow(string $workflowType, int $limit): self
    {
        return new self(sprintf(
            'Workflow "%s" exceeded %d transitions in one step without reaching a blocking state — '
            .'a transition cycle with no wait/stay/final.',
            $workflowType,
            $limit,
        ));
    }
}
