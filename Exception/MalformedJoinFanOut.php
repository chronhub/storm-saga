<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A join state's fan-out broke its promised shape, one command per declared arm, the race twin. A
 * missing arm's completion can never arrive, so the joining wait counts toward an arrival ledger
 * that will never fill; a duplicated arm splits one recall key across two rows, and a sibling
 * failure's disposition would read the recalled twin as proof the arm never dispatched, skipping
 * the undo of the command that did go out. The step rolls back loudly before any outbox write: the
 * fix is issuing exactly one command per arm from the fan-out state.
 */
final class MalformedJoinFanOut extends LogicException implements SagaException
{
    public static function armMissing(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Join state "%s" in workflow "%s" issued no command for arm "%s" — an arm that never dispatches can never complete, and the joining wait starves on a fan-out that lied about its width. Issue exactly one command per arm.',
            $stateKey,
            $workflow,
            $arm,
        ));
    }

    public static function armDuplicated(string $arm, string $stateKey, string $workflow): self
    {
        return new self(sprintf(
            'Join state "%s" in workflow "%s" issued two commands for arm "%s" — one recall key would cover both rows, and a recalled twin would read as proof the arm never dispatched, skipping the undo of the one that did. Issue exactly one command per arm.',
            $stateKey,
            $workflow,
            $arm,
        ));
    }
}
