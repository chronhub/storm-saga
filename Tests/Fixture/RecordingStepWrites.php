<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Store\OutboxEntry;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStepWrites;

/**
 * A step writer that keeps what each call hands it, so a test reads the effects and the sealed
 * entries a committer settled instead of the rows a store would have written.
 */
final class RecordingStepWrites implements WorkflowStepWrites
{
    /** @var list<array{row: WorkflowInstanceRow, effects: StepEffects, commands: list<OutboxEntry>}> */
    public array $advances = [];

    /** @var list<array{id: WorkflowId, effects: StepEffects, commands: list<OutboxEntry>}> */
    public array $applied = [];

    public function commitAdvance(WorkflowInstanceRow $row, StepEffects $effects, array $commands): void
    {
        $this->advances[] = ['row' => $row, 'effects' => $effects, 'commands' => $commands];
    }

    public function applyEffects(WorkflowId $id, StepEffects $effects, array $commands): void
    {
        $this->applied[] = ['id' => $id, 'effects' => $effects, 'commands' => $commands];
    }
}
