<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Event\SagaChildCancelSkipped;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowInstances;

/**
 * The receiving side of the cascade: proves parenthood on the child ROW, then cancels through the
 * engine's one cancellation channel. Never on identity alone: a cascade that targets a correlation
 * without proving the parent kills an unrelated workflow; here the row's own declaration is the
 * proof, and a failed proof is announced and dropped, never acted on.
 *
 * The quiet no-ops are the race resolving: a missing row is a child settled and pruned, and a
 * terminal child is the engine's own idempotent false. The cancel of a living child settles it
 * through halt and positional compensation, and THAT settle cascades to grandchildren through the
 * same seam that produced this command: transitive, durable, level by level.
 *
 * Three `false`s leave here, each with its designed voice or its designed silence:
 *
 * - The race resolving, a missing or terminal child: quiet, both sides absorb it;
 *
 * - A failed parenthood proof: announced as `SagaChildCancelSkipped`;
 *
 * - The engine REFUSING a non-forced cancel at an effect-gating wait: voiced by the engine itself
 *   as `SagaCancelRefused`.
 *
 * Invariant 2 holds for children too: a nominal abort never discards a child's in-flight effect, so
 * the child SURVIVES its parent, visibly, and resurfaces in the zombie sweep once the parent
 * settles; forcing the cascade would trade that invariant away, a decision no cascade takes
 * implicitly.
 */
final readonly class ChildCanceller
{
    public function __construct(
        private SagaOperator $engine,
        private WorkflowInstances $instances,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @return bool true when the engine applied a cancel; false on the quiet no-ops, the announced
     *              skips, and the engine's announced refusal of a non-forced cancel at a gating wait
     *
     * @throws InvalidChildIdentity when the child row carries a malformed parent declaration; corruption surfaces loudly
     * @throws SagaFenceBusy when a concurrent step holds the child's fence; retry the command
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function cancel(CancelChildWorkflow $command): bool
    {
        $child = $this->instances->findByCorrelation($command->childCorrelationId);

        if ($child === null) {
            return false;
        }

        $ref = $child->parentRef();

        if ($ref === null) {
            return $this->skip($command, 'not-a-child');
        }

        if ($ref->correlationId !== $command->parentCorrelationId) {
            return $this->skip($command, 'not-my-child');
        }

        if ($child->workflowType !== $command->childWorkflowType) {
            return $this->skip($command, 'type-mismatch');
        }

        return $this->engine->cancel(
            $child->workflowType,
            $child->correlationId,
            $command->reason,
            $command->force,
        );
    }

    private function skip(CancelChildWorkflow $command, string $reason): bool
    {
        $this->events->dispatch(new SagaChildCancelSkipped(
            $command->parentWorkflowType,
            $command->parentCorrelationId,
            // the parent's claimed run is not in hand on a skip path; 0 is the honest unknown
            0,
            $command->childWorkflowType,
            $command->childCorrelationId,
            $reason,
        ));

        return false;
    }
}
