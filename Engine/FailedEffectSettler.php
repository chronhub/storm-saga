<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Settle a saga whose issued command was dead-lettered while it is parked at its effect-gating
 * wait. A dead-letter means the handler transaction rolled back, so no effect committed and settling
 * is safe: halt on the spot and run the positional compensation. The dead-lettered step itself is
 * unconfirmed, skipped since there is no effect to undo; any earlier confirmed step rolls back.
 */
final readonly class FailedEffectSettler
{
    public function __construct(
        private Compensator $compensator,
    ) {}

    /**
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     */
    public function settle(WorkflowDefinition $def, WorkflowInstanceRow $row, ?string $causationId): Rested
    {
        $halted = $row->halted();

        return $this->compensator->compensate($def, $halted, new Rested($halted), $causationId, locationAgnostic: false);
    }
}
