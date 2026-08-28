<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use RuntimeException;
use Throwable;

/**
 * The shipped concrete `UnrecoverableCommandDispatch`. Throw it from a command publisher so
 * `SagaOutboxRelay` dead-letters the row immediately instead of spending the retry budget, which would
 * also delay the saga's compensation. Pass the cause as `$previous`; the relay records the chain in
 * `last_error`.
 *
 * `RuntimeException` per the exception policy, since a delivery rejection is a runtime condition, not a
 * bug. For an exception that already has its own hierarchy, implement `UnrecoverableCommandDispatch` on
 * it directly rather than throwing this.
 *
 * @see SagaOutboxRelay
 */
final class UnroutableCommand extends RuntimeException implements UnrecoverableCommandDispatch
{
    public static function reason(string $why, ?Throwable $previous = null): self
    {
        return new self($why, previous: $previous);
    }
}
