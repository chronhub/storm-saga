<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Event\CompensationFailed;

/**
 * How a saga's rollback reacts when one step's undo fails. `BestEffort`, the default, flags the failure as
 * `CompensationFailed` and keeps compensating the remaining steps, undoing as much as possible. `Strict`
 * stops the rollback at the first failed undo, leaving the rest `Pending` for manual reconciliation; use
 * it when later undoes must not run unless the earlier one succeeded.
 *
 * @see CompensationFailed
 */
enum CompensationMode: string
{
    case BestEffort = 'best_effort';

    case Strict = 'strict';
}
