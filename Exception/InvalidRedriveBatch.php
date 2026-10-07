<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use InvalidArgumentException;

/**
 * A batch redrive scope or candidate that breaks its own invariants, refused where it is built so no
 * storage call ever sees it. Deterministic; retrying cannot fix it.
 */
final class InvalidRedriveBatch extends InvalidArgumentException implements SagaException
{
    public static function blankWorkflowType(): self
    {
        return new self('A batch redrive needs a workflow type without surrounding whitespace.');
    }

    public static function limitOutOfRange(int $limit, int $max): self
    {
        return new self(sprintf('A batch redrive limit must lie between 1 and %d, got %d.', $max, $limit));
    }

    public static function negativeCursor(int $afterId): self
    {
        return new self(sprintf('A batch redrive cursor must not be negative, got %d.', $afterId));
    }

    public static function emptyWindow(): self
    {
        return new self('A batch redrive window needs its inclusive lower bound strictly before its exclusive upper bound.');
    }

    public static function nonPositiveId(int $id): self
    {
        return new self(sprintf('A batch redrive candidate names a positive outbox id, got %d.', $id));
    }
}
