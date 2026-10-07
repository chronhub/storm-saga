<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationStatus;

/**
 * The rollback's recall judge, run under the step's fence before the `Compensator` walks the log, on
 * every path that compensates. Only a halted run is judged, a run that did not halt owing no rollback.
 * Each entry still `Pending` and unconfirmed is judged on its persisted forward rows, whatever
 * their status. Unclaimed rows stay locked until the step commits. A nonempty set with no claim
 * proves nothing left, so nothing needs undoing. Relay claims skip these locked rows until the
 * abort cancels them. Compensable emissions enter an explicit wait before any later halt.
 *
 * No proof by vacuity: an entry that issued no forward command rolls back on its own eligibility, its
 * effect being whatever its activity did. A confirmed entry is never judged, its effect known to have
 * happened.
 */
final readonly class RecallJudge
{
    public function __construct(
        private WorkflowOutbox $outbox,
    ) {}

    /**
     * @throws SagaStorageFailure when an entry's rows cannot be read and locked; the step rolls back whole
     */
    public function judge(Rested $run): RecallVerdict
    {
        $row = $run->row;
        if ($row->status !== WorkflowStatus::Halted) {
            return RecallVerdict::none(); // no rollback is owed, so no row is read or locked
        }

        $recalled = [];

        foreach ($row->compensations as $entry) {
            if ($entry->status !== CompensationStatus::Pending || $entry->confirmed) {
                continue;
            }

            $claims = $this->outbox->forwardClaims($row->id(), $row->generation, $entry->step, $entry->arm);

            if ($claims !== [] && ! in_array(true, $claims, true)) {
                $recalled[] = $entry;
            }
        }

        return RecallVerdict::of(...$recalled);
    }
}
