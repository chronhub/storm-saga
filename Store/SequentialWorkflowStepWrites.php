<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Outbox\WorkflowOutbox;

/**
 * {@inheritDoc}
 *
 * The composition over the three ports, one statement each, for a store that has no single-statement
 * write: the in-memory runtime, or a DBAL runtime handed custom stores.
 */
final readonly class SequentialWorkflowStepWrites implements WorkflowStepWrites
{
    public function __construct(
        private WorkflowInstances $instances,
        private WorkflowTimers $timers,
        private WorkflowOutbox $outbox,
    ) {}

    public function commitAdvance(WorkflowInstanceRow $row, StepEffects $effects, array $commands): void
    {
        $this->instances->update($row);
        $this->applyEffects(new WorkflowId($row->workflowType, $row->correlationId), $effects, $commands);
    }

    public function applyEffects(WorkflowId $id, StepEffects $effects, array $commands): void
    {
        foreach ($effects->cancels as ['stateKey' => $stateKey]) {
            // the arms below re-create the kinds a cancel would spare, so clearing the key is exact
            $this->timers->cancel($id, $stateKey);
        }
        foreach ($effects->arms as ['stateKey' => $stateKey, 'kind' => $kind, 'fireAt' => $fireAt]) {
            $this->timers->arm($id, $stateKey, $kind, $fireAt);
        }
        foreach ($commands as $entry) {
            $this->outbox->writeEntry($id, $entry);
        }
    }
}
