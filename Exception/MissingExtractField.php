<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;
use Storm\Saga\Workflow\WaitState;

/**
 * A wait's `#[WaitFor(extract:)]` map named a payload field that the matched event doesn't carry: a
 * declaration/wiring bug, a typo or wrong field name, not a runtime condition. The matcher already
 * accepted the event, so its payload shape is the workflow's contract. `LogicException`: fail fast and
 * loud, never write a silent null into `vars`. The method form reads the typed event and handles
 * optional fields itself; the map form is for fields that are always present.
 *
 * @see WaitState
 */
final class MissingExtractField extends LogicException implements SagaException
{
    public static function inWait(string $field, string $stateKey): self
    {
        return new self(sprintf('#[WaitFor(state: "%s")] extract maps the field "%s", absent from the matched event\'s payload.', $stateKey, $field));
    }
}
