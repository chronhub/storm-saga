<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

/**
 * The persisted lifecycle status of a saga instance, and what the engine actually writes:
 *
 * - `Running`, including waiting; the state subtype already says what it waits on.
 *
 * - `Completed`.
 *
 * - `Halted`, a dead end.
 *
 * - `Compensated`, rolled back; a terminal failure whose completed steps were undone.
 */
enum WorkflowStatus: string
{
    case Running = 'running';

    case Completed = 'completed';

    case Halted = 'halted';

    case Compensated = 'compensated';
}
