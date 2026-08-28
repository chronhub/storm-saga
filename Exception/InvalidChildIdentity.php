<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;

/**
 * The child-identity gate refused a birth. The delimiter of {@see ChildCorrelation} is a reserved
 * namespace: a native start whose correlation id contains it is refused, a start that declares a
 * {@see ParentRef} must carry the exactly minted correlation, and a declared parent must be
 * well formed. Deterministic; retrying cannot fix it.
 *
 * Messages escape the delimiter as `\x1F`: the raw unit separator is a control character that would
 * corrupt logs and dead-letter rows.
 */
final class InvalidChildIdentity extends RuntimeException implements SagaException
{
    public static function reservedNamespace(string $correlationId): self
    {
        return new self(sprintf(
            'Correlation "%s" lies in the child namespace — the delimiter is reserved; a child is born through a spawn declaring its parent, never through a native start.',
            self::printable($correlationId),
        ));
    }

    public static function correlationMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'A start declaring a parent must carry the minted child correlation "%s", got "%s" — the deterministic identity is the idempotence guard, it cannot be free-formed.',
            self::printable($expected),
            self::printable($actual),
        ));
    }

    public static function invalidSlot(string $slot): self
    {
        return new self(sprintf(
            'Child slot "%s" is invalid — a slot is a static identifier, 1 to 64 characters of [A-Za-z0-9_-].',
            self::printable($slot),
        ));
    }

    public static function malformedParentContext(string $reason): self
    {
        return new self(sprintf('Malformed "%s" context: %s', ParentRef::CONTEXT_KEY, $reason));
    }

    /**
     * Escapes the reserved delimiter and every other C0 control so the message stays log-safe.
     */
    private static function printable(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1f\x7f]/',
            static fn (array $match): string => sprintf('\x%02X', ord($match[0])),
            $value,
        );
    }
}
