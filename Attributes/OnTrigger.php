<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

/**
 * What fires a transition out of a state. An activity yields `Success`/`Failure`; a wait state
 * yields `Event` when a matching event arrives; a schedule state yields `Schedule` when its cadence
 * comes due; a timed wait/activity yields `Timeout` when its timer fires.
 */
enum OnTrigger: string
{
    case Success = 'success';

    case Failure = 'failure';

    case Timeout = 'timeout';

    case Event = 'event';

    case Schedule = 'schedule';
}
