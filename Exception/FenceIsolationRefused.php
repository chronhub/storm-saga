<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * The step fence found its transaction running under an isolation level other than `READ COMMITTED`
 * and refused the step before anything settled.
 *
 * The fence's freshness argument is written for `READ COMMITTED` alone: the family gate counts the
 * parent's living members with plain reads, and only a per-statement snapshot lets a child committed a
 * moment ago be seen. Under `REPEATABLE READ` or `SERIALIZABLE` the snapshot predates that commit and
 * the miss is silent, no serialization failure ever names it, so the level is refused outright rather
 * than argued about. A `LogicException`: the level comes from wiring, an ambient transaction opened by
 * a delivery seam or a session default set by a pooler, and retrying the step cannot change it.
 */
final class FenceIsolationRefused extends LogicException implements SagaException
{
    public static function underAmbientTransaction(string $effectiveLevel): self
    {
        return new self(sprintf(
            'The saga step fence runs under an ambient transaction at isolation level "%s"; READ COMMITTED is required '
            .'for the family reads to see a freshly committed child. The level belongs to the caller that opened '
            .'the transaction, typically a delivery seam wrapping the handlers, or to a pooler or raw SQL that set '
            .'the session default; open that transaction READ COMMITTED or let the fence own its own.',
            $effectiveLevel,
        ));
    }

    public static function underOwnedTransaction(string $effectiveLevel): self
    {
        return new self(sprintf(
            'The saga step fence opened its own transaction and found it at isolation level "%s"; READ COMMITTED is '
            .'required for the family reads to see a freshly committed child. DBAL tracks the level it set itself, '
            .'so this one was set behind it, by raw SQL or a pooler default; reset the session default to READ COMMITTED.',
            $effectiveLevel,
        ));
    }
}
