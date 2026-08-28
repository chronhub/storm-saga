<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A race state issued a command no declared arm owns. The outbox stamping matches on the command
 * class, and an unattributed command would ride ungrouped, invisible to the targeted recall,
 * undisposed at the victory, a racer with no number. The step rolls back loudly instead: the fix is
 * declaring the arm, or issuing the command from a state that is not a race.
 */
final class UnattributedRaceCommand extends LogicException implements SagaException
{
    public static function forCommand(string $commandClass, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Race state "%s" in workflow "%s" issued %s, which no declared #[RaceArm] owns — an unattributed command cannot be recalled or disposed of at the victory. Declare the arm, or issue it elsewhere.',
            $stateKey,
            $workflow,
            $commandClass,
        ));
    }
}
