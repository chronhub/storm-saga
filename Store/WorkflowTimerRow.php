<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Clock\PointInTime;

/**
 * A claimed `workflow_timers` row, as the timer runner sees it: enough to dispatch the right message
 * from `workflowType`, `correlationId`, `stateKey`, and `kind`, the `fireAt` instant it was due at as a
 * schedule slot's catch-up anchor, and the `id` to remove the row afterward.
 */
final readonly class WorkflowTimerRow
{
    public function __construct(
        public int $id,
        public string $workflowType,
        public string $correlationId,
        public string $stateKey,
        public TimerKind $kind,
        public PointInTime $fireAt,
    ) {}
}
