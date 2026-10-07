<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Event\SagaCancelled;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Execute an operator's cancel: halt the saga where it sits and run the positional compensation.
 * Confirmed steps and the untracked ones the saga moved past roll back, save a step whose commands
 * the recall proves never left, skipped with nothing to undo; an unconfirmed tracked step, the
 * in-flight effect under `--force`, is skipped and flagged, never blindly compensated, with invariant
 * 4 the safety net even here. `SagaCancelled(reason)` rides first, then the rollback's events follow.
 */
final readonly class Canceller
{
    public function __construct(
        private Compensator $compensator,
        private RecallJudge $recalls,
    ) {}

    /**
     * @throws SagaStorageFailure when the recall's rows cannot be read and locked; the step rolls back whole
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     */
    public function cancel(WorkflowDefinition $def, WorkflowInstanceRow $row, ?string $reason, ?string $causationId): Rested
    {
        $halted = $row->halted();
        $cancelled = new SagaCancelled($row->workflowType, $row->correlationId, $row->generation, $row->stateKey, $reason);
        $carried = new Rested($halted, [$cancelled]);

        return $this->compensator->compensate($def, $halted, $carried, $causationId, locationAgnostic: false, recalled: $this->recalls->judge($carried));
    }
}
