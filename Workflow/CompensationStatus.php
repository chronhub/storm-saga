<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * The lifecycle of one entry in a saga's rollback log, a `CompensationRecord`. `Pending` is set when a
 * completed compensatable step is logged during the forward run; the rollback then resolves each entry to
 * `Compensated` when its undo ran, `Skipped` when it is not rolled back because its effect was not
 * confirmed and undoing it could reverse something that never happened, or `Failed` when its undo threw or
 * returned a failure, flagged for reconciliation.
 *
 * @see CompensationRecord
 */
enum CompensationStatus: string
{
    case Pending = 'pending';

    case Compensated = 'compensated';

    case Skipped = 'skipped';

    case Failed = 'failed';
}
