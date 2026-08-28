<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use InvalidArgumentException;
use Storm\Saga\Store\WorkflowId;

/**
 * A saga identity that storage could not carry faithfully, refused where it is built rather than
 * where it would break. Deterministic; retrying cannot fix it.
 *
 * @see WorkflowId for what each rule protects
 */
final class InvalidWorkflowId extends InvalidArgumentException implements SagaException
{
    public static function empty(string $field): self
    {
        return new self(sprintf('A saga identity needs a non-blank "%s".', $field));
    }

    public static function tooLong(string $field, int $max, int $actual): self
    {
        return new self(sprintf(
            'A saga identity "%s" is capped at %d bytes, got %d. The value lands in indexed columns whose'
            .' PostgreSQL b-tree rows cap out near 2 700 bytes, so an unbounded id is accepted by the'
            .' engine and then fails deep inside a step.',
            $field,
            $max,
            $actual,
        ));
    }

    public static function malformed(string $field): self
    {
        return new self(sprintf(
            'A saga identity "%s" carries a control or format character. The text path TRUNCATES silently at'
            .' a NUL, so two distinct ids would converge on one stored row — one saga answering for'
            .' another. Only the reserved child delimiter is allowed.',
            $field,
        ));
    }
}
