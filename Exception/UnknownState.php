<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * A `WorkflowDefinition` was asked for a state key it doesn't declare: a definition/transition wiring
 * bug, a `#[Start]` or `#[On(to:)]` pointing at a missing state, not a runtime condition.
 * `LogicException`: fail fast, fix the definition.
 *
 * @see WorkflowDefinition
 */
final class UnknownState extends LogicException implements SagaException
{
    public static function inWorkflow(string $key, string $workflow): self
    {
        return new self(sprintf('Unknown state "%s" in workflow "%s".', $key, $workflow));
    }
}
