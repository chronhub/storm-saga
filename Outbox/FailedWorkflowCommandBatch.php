<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Exception\FenceIsolationRefused;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The batch redrive capability: select one bounded page of dead-lettered commands proven uncommitted,
 * then send each back into flight by its primary key, one row at a time.
 *
 * A port distinct from `FailedWorkflowCommands`, so an application implementing the unit port owes
 * nothing to this one. It carries no force: only a command whose evidence proves the effect
 * uncommitted is ever selected or redriven. Atomicity is per row; no transaction spans the page, and
 * a page is a passage over the table at one moment, not an exhaustive snapshot.
 */
interface FailedWorkflowCommandBatch
{
    /**
     * Select at most `$scope->limit` commands eligible for an unforced redrive, ordered by id.
     *
     * Eligible means `failed`, evidence `uncommitted`, of the scope's workflow type and window, with
     * an id past the cursor, and owned by the current generation of a running saga. The selection
     * reads only the identity each candidate needs, never the payload, the header or the last error,
     * and it reserves nothing: a candidate may be refused by the time it is redriven.
     *
     * @return list<RedriveCandidate>
     *
     * @throws SagaStorageFailure when the storage fails
     */
    public function candidates(RedriveBatchScope $scope): array;

    /**
     * Revalidate and redrive exactly the candidate's primary key under its saga's step fence.
     *
     * The row must still carry the candidate's workflow type and correlation, else `NotFound`: a
     * stale candidate never locks one saga and mutates another. Every guard of the unit redrive is
     * re-checked in the statement that flips the row, and a busy fence answers `Raced` without
     * waiting. Inside a caller's ambient transaction the flip joins it and the fence stays held until
     * the outer commit; `Redriven` then promises only what that transaction commits.
     *
     * @throws FenceIsolationRefused when a transactional adapter requires `READ COMMITTED` and the caller uses another isolation level
     * @throws SagaStorageFailure when the storage fails; the outcome of the row is then unknown
     */
    public function redrive(RedriveCandidate $candidate): RedriveOutcome;
}
