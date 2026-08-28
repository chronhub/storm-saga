<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;
use Storm\Saga\Build\WorkflowRegistry;

/**
 * The `WorkflowRegistry` was asked for a workflow name it doesn't know: a wiring/usage bug, the class
 * wasn't discovered or the name is wrong, not a runtime condition.
 *
 * @see WorkflowRegistry
 */
final class WorkflowNotFound extends LogicException implements SagaException
{
    public static function named(string $name): self
    {
        return new self(sprintf('No workflow registered under "%s".', $name));
    }
}
