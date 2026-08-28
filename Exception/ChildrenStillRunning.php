<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A parent tried to settle NOMINALLY with living children, the runtime floor of the settle
 * guard: the coupling of life is the feature, so a completion that would orphan running children
 * is refused and the step rolls back, loudly, instead of leaving a tree without its root. The fix
 * is in the definition: a spawned slot must have a terminal wait, so the parent cannot reach its
 * final state before every child's outcome landed.
 *
 * The ABORT settles never raise this: cancel, halt, and compensation cascade instead.
 * Deterministic; retrying cannot fix it.
 */
final class ChildrenStillRunning extends RuntimeException implements SagaException
{
    /**
     * @param  array<string, string>  $declaredAwaits  slot => awaitedBy from the parent's `#[Spawns]` declarations,
     *                                                 so the refusal names the wait that should have consumed each child
     */
    public static function atNominalSettle(string $workflowType, string $correlationId, int $living, array $declaredAwaits = []): self
    {
        $declared = $declaredAwaits === []
            ? ''
            : ' Declared: '.implode(', ', array_map(
                static fn (string $slot, string $awaitedBy): string => sprintf('%s → awaited by "%s"', $slot, $awaitedBy),
                array_keys($declaredAwaits),
                $declaredAwaits,
            )).'.';

        return new self(sprintf(
            'Workflow "%s" ("%s") cannot complete with %d living child(ren): a spawned slot must hold a terminal wait — completing now would orphan the tree, so the settle rolls back.%s',
            $workflowType,
            $correlationId,
            $living,
            $declared,
        ));
    }
}
