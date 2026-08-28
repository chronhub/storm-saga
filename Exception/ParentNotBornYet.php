<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Saga\Child\ChildSpawner;

/**
 * A spawn found NO parent row: the start-versus-spawn race, the inverse of cancel-versus-spawn. The
 * parent's handler dispatches `StartChildWorkflow` from inside its own inbox transaction, so the
 * broker holds the spawn before the parent row commits; a parallel worker consuming it in that
 * window sees no row. Only a parent's handler mints the command, so its existence IS the proof a
 * parent start is in flight: the absence is transient by construction and the spawn must be
 * REDELIVERED, never acked. Acking it as an announced skip loses the child forever, silently, while
 * the composition's other arm, the outcome delivery to the unborn child, keeps redelivering toward
 * a birth that never comes.
 *
 * Deliberately a plain RuntimeException, retryable under Messenger's default policy, never marked
 * unrecoverable, the `SagaFenceBusy` shape. The lane's retry budget is the discriminator this race
 * cannot compute from the row alone: a racing start commits within a redelivery or two, while a
 * parent that never commits dead-letters the spawn as replayable evidence of the failed start,
 * proof where a skip would produce silence. The settled-parent direction keeps its announced skip:
 * a PRESENT terminal row skips as `parent-terminal`, and the locked adoption proof inside the birth
 * still routes its own `parent-missing`, a row that vanished BETWEEN the pre-check and the lock,
 * meaning erased or pruned, to the skip channel via `ParentNotAdoptable`.
 *
 * @see ChildSpawner the pre-check that raises this
 * @see ParentNotAdoptable the settled/pruned family, which stays a skip
 * @see SagaFenceBusy the retryable-by-default precedent this mirrors
 */
final class ParentNotBornYet extends RuntimeException implements SagaException
{
    public static function forSpawn(string $parentWorkflowType, string $parentCorrelationId, string $childWorkflowType, string $slot): self
    {
        return new self(sprintf(
            'Spawn of %s child "%s" (slot %s) found no row for parent %s/%s — the parent start is still in flight; redeliver the spawn.',
            $childWorkflowType, $parentCorrelationId, $slot, $parentWorkflowType, $parentCorrelationId,
        ));
    }
}
