<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Exception\CorrelationAlreadyConsumed;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Workflow\CorrelationReuse;

/**
 * The saga instance repository capability: find, create under the durable correlation claim,
 * update under OCC, delete. The step's persistence core; the family, pause, and maintenance
 * capabilities are sibling ports one adapter implements together. Writes MUST enlist in the step's
 * single unit of work per `SagaStepUnitOfWork` law 1, and every method may throw the port-owned
 * `SagaStorageFailure` with the driver's failure wrapped; only the port's own semantics are thrown
 * as themselves.
 *
 * @see SagaStepUnitOfWork
 * @see SagaStorageFailure
 */
interface WorkflowInstances
{
    /**
     * Find the instance with this `id`. Returns null when none matches.
     */
    public function find(WorkflowId $id): ?WorkflowInstanceRow;

    /**
     * Find the unique instance with this `correlationId` across all workflow types, used by
     * framework-level generic routing such as `Engine::deliverByCorrelation` and `failIssuedEffect`
     * where the caller only has the correlation, carried by the message context and the dead-letter
     * signal. Returns null when none matches. A saga's `correlationId` is a unique UUID, so a match is
     * single.
     */
    public function findByCorrelation(string $correlationId): ?WorkflowInstanceRow;

    /**
     * Insert a fresh instance on the first step, and claim its correlation durably under `$reuse`, the
     * workflow's declared policy. Its `version` starts at the row's value of 0.
     *
     * @return int the run's `generation`, 1 under `Reject` and for a correlation's first run, the next
     *             number when `Allow` lets a business key run again; the caller stamps it on the row so
     *             every artifact of this run carries it
     *
     * @throws CorrelationAlreadyOwned when another saga type already owns this correlation id; one saga
     *                                 per correlation is a schema-enforced invariant
     * @throws CorrelationAlreadyConsumed when this correlation already spent its single run under
     *                                    `Reject`; a claim outlives the instance
     * @throws SagaStateTooLarge when the JSON bags together exceed the state cap
     */
    public function create(WorkflowInstanceRow $row, CorrelationReuse $reuse = CorrelationReuse::Reject): int;

    /**
     * Persist a step's result, guarded by OCC: updates only where the stored `version` equals
     * `$row->version`, then bumps it.
     *
     * @throws StaleWorkflowInstance when the version moved on because a competing step won
     * @throws SagaStateTooLarge when the JSON bags together exceed the state cap; the step rolls back
     *                           rather than persisting a state the next one would have to carry
     */
    public function update(WorkflowInstanceRow $row): void;

    /**
     * Remove the instance with this `id`.
     */
    public function delete(WorkflowId $id): void;
}
