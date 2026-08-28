<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Saga\Exception\InvalidChildIdentity;

/**
 * Mints and reads the deterministic identity of a child saga: the parent's correlation id, the
 * ASCII unit separator, then the slot name. Determinism is the idempotence guard itself: a
 * row-mutable engine has no replay to deduplicate a second spawn, so the same parent-and-slot pair
 * MUST resolve to the same correlation id, and the schema's unique correlation index then turns a
 * redelivered spawn into the port's idempotent no-op instead of a second child.
 *
 * The delimiter is RESERVED, the same doctrine as the `$` stream prefix: the engine refuses a
 * native start whose correlation id contains it, so a child correlation can only be minted here.
 * This closes the silent-adoption hole: an application correlation shaped like a child identity
 * would otherwise be adopted by a spawning parent through the idempotent same-type start, without
 * a sound.
 *
 * A child may itself spawn: a parent correlation id containing the delimiter is a child acting as
 * parent. A slot can never contain the delimiter, so `split()` reads the LAST segment as the slot.
 */
final class ChildCorrelation
{
    /**
     * The ASCII unit separator, the same character the composite fence key uses: no legitimate
     * application correlation carries a C0 control, which is what makes the reservation painless.
     */
    public const string DELIMITER = "\x1f";

    /**
     * The hard depth ceiling of a lineage: no surveyed engine publishes one, and a saga spawning
     * itself in a loop is a classic production incident, so the ceiling is the runtime pendant of
     * the compensatable-cycle build gate. Static slots make real lineages two or three deep; eight
     * is generous headroom, not an invitation.
     */
    public const int MAX_DEPTH = 8;

    /**
     * A slot is a static identifier declared by the parent workflow, never runtime data: the
     * restriction keeps child correlations log-safe and URL-safe past the delimiter itself.
     */
    private const string SLOT_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    // No `new ChildCorrelation(...)` call site exists anywhere in src/ or tests/: the class is a
    // static-only minter, read through mint(), split(), root(), isChild(), and isValidSlot(). This
    // constructor exists solely to block instantiation, so it is unreachable except through
    // Reflection, which would assert nothing about behavior.
    // @codeCoverageIgnoreStart
    private function __construct() {}

    // @codeCoverageIgnoreEnd

    /**
     * @throws InvalidChildIdentity when the slot is not a valid static identifier, or the parent correlation id is empty
     */
    public static function mint(string $parentCorrelationId, string $slot): string
    {
        if ($parentCorrelationId === '') {
            throw InvalidChildIdentity::malformedParentContext('parent correlation id must not be empty');
        }

        if (! self::isValidSlot($slot)) {
            throw InvalidChildIdentity::invalidSlot($slot);
        }

        return $parentCorrelationId.self::DELIMITER.$slot;
    }

    public static function isChild(string $correlationId): bool
    {
        return str_contains($correlationId, self::DELIMITER);
    }

    public static function isValidSlot(string $slot): bool
    {
        return preg_match(self::SLOT_PATTERN, $slot) === 1;
    }

    /**
     * Reads a minted identity back into its parent correlation id and slot. The parent side may
     * itself contain the delimiter, since a grandchild's parent is a child, so the slot is the
     * segment after the LAST delimiter.
     *
     * @return array{parentCorrelationId: string, slot: string}
     *
     * @throws InvalidChildIdentity when the correlation id is not in the child namespace
     */
    public static function split(string $correlationId): array
    {
        $at = strrpos($correlationId, self::DELIMITER);

        if ($at === false) {
            throw InvalidChildIdentity::malformedParentContext(sprintf('correlation "%s" is not a child identity', $correlationId));
        }

        return [
            'parentCorrelationId' => substr($correlationId, 0, $at),
            'slot' => substr($correlationId, $at + 1),
        ];
    }

    /**
     * The indexed family a member SLOT belongs to, or null when the slot is not a member form.
     *
     * The grammar of a family is one declaration and many members: `#[Spawns(indexed: true)]`
     * declares the slot `leg`, and the spawn commands name `leg-0 … leg-n`. The suffix must be
     * DIGITS, which is the whole rule: a static slot named `leg-final` beside a family `leg` is a
     * different slot, not a member, and the build gate admits it precisely because it cannot
     * collide with one. The family name may itself carry hyphens, so the split is on the LAST one.
     *
     * The single home of that grammar: the consent proof resolves a member's declaration through
     * it, and the settle reads it to know whether a concluding child can complete a family.
     */
    public static function familyOfSlot(string $slot): ?string
    {
        // anchored at BOTH ends: `.` does not cross a newline, so without the caret a second line
        // would answer for the whole string and `"x\nleg-1"` would name a family the slot grammar
        // never admitted. Unreachable through a validated slot, and cheaper to anchor than to trust
        if (preg_match('/^(.+)-\d+$/', $slot, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Reads the root correlation id of a lineage: the segment before the FIRST delimiter, the
     * ancestor every generation descends from. Not the parent: `split()` reads before the LAST
     * delimiter, and a grandchild's parent is itself a child whose id still carries the delimiter,
     * so it names nothing writable as a stream qualifier. Total by design: a non-child correlation
     * id is returned unchanged, so a call site grouping by root needs no `isChild()` guard.
     */
    public static function root(string $correlationId): string
    {
        $at = strpos($correlationId, self::DELIMITER);

        return $at === false ? $correlationId : substr($correlationId, 0, $at);
    }
}
