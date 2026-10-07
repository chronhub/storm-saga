<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\OnTrigger;

/**
 * The outcome an `Activity` reports via `ActivityResult`. `Success` and `Failure` map to the matching
 * `OnTrigger` transition; `Async` rests in the same activity without emitting commands. A subsequent
 * wake re-executes the activity; its declared timeout bounds the stay.
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
