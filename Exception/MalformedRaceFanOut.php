<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A race state's fan-out broke its promised shape, one command per declared arm. A missing arm can
 * never produce an outcome, so the settling wait starves on a race that lied about its own width; a
 * duplicated arm splits one recall key across two rows, and the victory's disposition would read the
 * recalled twin as proof the arm never dispatched, skipping the undo of the command that did go out.
 * The step rolls back loudly before any outbox write: the fix is issuing exactly one command per arm
 * from the fan-out state.
 */
final class MalformedRaceFanOut extends LogicException implements SagaException
{
    public static function armMissing(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Race state "%s" in workflow "%s" issued no command for arm "%s" — an arm that never dispatches can never win nor be disposed of, and the race lies about its own width. Issue exactly one command per arm.',
            $stateKey,
            $workflow,
            $arm,
        ));
    }

    public static function armDuplicated(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Race state "%s" in workflow "%s" issued two commands for arm "%s" — one recall key would cover both rows, and a recalled twin would read as proof the arm never dispatched, skipping the undo of the one that did. Issue exactly one command per arm.',
            $stateKey,
            $workflow,
            $arm,
        ));
    }
}
