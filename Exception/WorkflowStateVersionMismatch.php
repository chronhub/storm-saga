<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Throwable;

/**
 * A stored instance's `state_version` disagrees with the workflow's declared
 * `#[Workflow(stateVersion:)]`, in either direction, so the engine refuses to run the step: feeding a
 * bag of one shape to activities expecting another would execute effects on misread data. Both
 * directions resolve by a DEPLOY, never by a retry:
 *
 * - AHEAD: the row was written by newer code than what is running; roll the code forward.
 *
 * - BEHIND: the row predates the declaration and no migration path exists to bridge it; register
 *   the state migrator for the gap, or roll the declaration back.
 */
final class WorkflowStateVersionMismatch extends RuntimeException implements SagaException
{
    public static function aheadOfCode(string $workflowType, string $correlationId, int $stored, int $declared): self
    {
        return new self(sprintf(
            'Workflow "%s" instance "%s" carries state_version %d but the running code declares %d: '
            .'the row was written by NEWER code. Refusing to run it with an older reader; deploy forward.',
            $workflowType, $correlationId, $stored, $declared,
        ));
    }

    public static function behindWithoutMigrator(string $workflowType, string $correlationId, int $stored, int $declared): self
    {
        return new self(sprintf(
            'Workflow "%s" instance "%s" carries state_version %d but the running code declares %d, '
            .'and no migration path bridges the gap. Refusing to run activities on an older shape; '
            .'register the state migrator, or roll the declaration back.',
            $workflowType, $correlationId, $stored, $declared,
        ));
    }

    public static function chainBroke(string $workflowType, string $correlationId, int $atHop, int $declared, Throwable $cause): self
    {
        return new self(sprintf(
            'Workflow "%s" instance "%s" broke its state migration at hop %d -> %d (declared %d): %s. '
            .'Nothing partial was persisted; fix the migrator and the chain re-runs whole.',
            $workflowType, $correlationId, $atHop, $atHop + 1, $declared, $cause->getMessage(),
        ), previous: $cause);
    }
}
