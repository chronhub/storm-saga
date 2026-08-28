<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\OnTrigger;

/**
 * The outcome an `Activity` reports via `ActivityResult`. `Success` and `Failure` map to the matching
 * `OnTrigger` transition; `Async` means the activity dispatched work and the result will arrive later as
 * an event, so the saga moves to a wait state and nothing transitions yet.
 *
 * @see Activity
 * @see ActivityResult
 * @see OnTrigger
 */
enum ActivityOutcome
{
    case Success;

    case Failure;

    case Async;
}
