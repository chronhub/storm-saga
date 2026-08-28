<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use InvalidArgumentException;
use Storm\Saga\Workflow\Metadata;

/**
 * `Metadata` was built with an identifier storage could not carry faithfully: blank, over the byte
 * cap, or carrying a control or format character the text path cannot round-trip. A caller/wiring
 * bug, raised as `InvalidArgumentException` in the `LogicException` family: fail fast.
 *
 * @see Metadata
 */
final class InvalidMetadata extends InvalidArgumentException implements SagaException
{
    public static function empty(string $field): self
    {
        return new self(sprintf('%s cannot be empty.', $field));
    }

    public static function tooLong(string $field, int $max, int $got): self
    {
        return new self(sprintf('%s exceeds the maximum length of %d bytes (got %d).', $field, $max, $got));
    }

    public static function malformed(string $field): self
    {
        return new self(sprintf(
            'Activity metadata "%s" carries a control or format character; the text path truncates silently'
            .' at a NUL, so two distinct values would converge on one stored row.',
            $field,
        ));
    }
}
