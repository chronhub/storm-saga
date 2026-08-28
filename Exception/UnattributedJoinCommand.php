<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A join state issued a command no declared arm owns. The outbox stamping matches on the command
 * class, so an unattributed command would ride ungrouped:
 *
 * - Invisible to the targeted recall.
 * - Undisposed at a sibling arm's failure.
 * - An arrival nobody counts.
 *
 * The step rolls back loudly instead: the fix is declaring the arm, or issuing the command from a
 * state that is not a join.
 */
final class UnattributedJoinCommand extends LogicException implements SagaException
{
    public static function forCommand(string $commandClass, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Join state "%s" in workflow "%s" issued %s, which no declared #[JoinArm] owns — an unattributed command cannot be recalled or disposed of when a sibling arm fails. Declare the arm, or issue it elsewhere.',
            $stateKey,
            $workflow,
            $commandClass,
        ));
    }
}
