<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Event\SagaCancelled;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Execute an operator's cancel: halt the saga where it sits and run the positional compensation.
 * Completed steps really completed, progression being the proof, so confirmed and
 * positionally-implied effects roll back; an unconfirmed tracked step, the in-flight effect under
 * `--force`, is skipped and flagged, never blindly compensated, with invariant 4 the safety net even
 * here. `SagaCancelled(reason)` rides first, then the rollback's events follow.
 */
final readonly class Canceller
{
    public function __construct(
        private Compensator $compensator,
    ) {}

    /**
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     */
    public function cancel(WorkflowDefinition $def, WorkflowInstanceRow $row, ?string $reason, ?string $causationId): Rested
    {
        $halted = $row->halted();
        $cancelled = new SagaCancelled($row->workflowType, $row->correlationId, $row->generation, $row->stateKey, $reason);

        return $this->compensator->compensate($def, $halted, new Rested($halted, [$cancelled]), $causationId, locationAgnostic: false);
    }
}
