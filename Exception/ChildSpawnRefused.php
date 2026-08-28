<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Saga\Child\ChildSpawner;

/**
 * A spawn crossed a deterministic guard:
 *
 * - One of the quantitative ceilings of {@see ChildSpawner}: the depth ceiling that kills a spawn
 *   cycle, the per-parent children ceiling, the vars payload ceiling;
 *
 * - The CONSENT proof at birth: a slot the parent's pinned definition never declared, or declared
 *   for another child type.
 *
 * Retrying cannot fix either; the throw dead-letters the spawn and settles the PARENT's leg, the
 * same path any poisoned issued command takes, never the announced-skip channel, which belongs to
 * the races.
 */
final class ChildSpawnRefused extends RuntimeException implements SagaException
{
    public static function depthExceeded(string $childWorkflowType, string $slot, int $depth, int $max): self
    {
        return new self(sprintf(
            'Spawning "%s" in slot "%s" would reach depth %d, past the ceiling of %d — a lineage this deep is a spawn cycle, not a design.',
            $childWorkflowType,
            $slot,
            $depth,
            $max,
        ));
    }

    public static function tooManyChildren(string $parentCorrelationId, int $count, int $max): self
    {
        return new self(sprintf(
            'Parent "%s" already has %d children, at the ceiling of %d — static slots are few by design; a parent at this ceiling is spawning in a loop.',
            $parentCorrelationId,
            $count,
            $max,
        ));
    }

    public static function slotUndeclared(string $parentWorkflowType, string $slot, string $childWorkflowType): self
    {
        return new self(sprintf(
            'Parent workflow "%s" never declared spawn slot "%s" (child "%s" refused). Consent is compile-time: '
            .'add #[Spawns(slot: \'%s\', workflow: \'%s\', awaitedBy: ...)] to the parent, or stop composing this '
            .'child under it — a declaration bug, not a race.',
            $parentWorkflowType,
            $slot,
            $childWorkflowType,
            $slot,
            $childWorkflowType,
        ));
    }

    public static function slotChildMismatch(string $parentWorkflowType, string $slot, string $declared, string $actual): self
    {
        return new self(sprintf(
            'Parent workflow "%s" declares slot "%s" for child "%s", but "%s" tried to be born under it. '
            .'One slot, one declared child type — a composition bug, not a race.',
            $parentWorkflowType,
            $slot,
            $declared,
            $actual,
        ));
    }

    public static function varsTooLarge(int $bytes, int $max): self
    {
        return new self(sprintf(
            'Spawn vars weigh %d bytes, past the ceiling of %d — pass a reference, not the payload; the row is not a document store.',
            $bytes,
            $max,
        ));
    }
}
