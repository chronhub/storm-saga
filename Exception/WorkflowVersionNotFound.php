<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;
use Storm\Saga\Build\WorkflowRegistry;

/**
 * The `WorkflowRegistry` knows the workflow name but not the requested version: typically an instance
 * pinned to a version whose definition was purged too early, its class removed while instances were
 * still running under it. The version-aware twin of {@see WorkflowNotFound}; fails loud so a stranded
 * instance is never silently mis-driven by another version.
 *
 * @see WorkflowRegistry
 */
final class WorkflowVersionNotFound extends LogicException implements SagaException
{
    public static function for(string $name, int $version): self
    {
        return new self(sprintf(
            'Workflow "%s" has no registered version %d (was its definition purged while an instance was still pinned to it?).',
            $name, $version,
        ));
    }
}
