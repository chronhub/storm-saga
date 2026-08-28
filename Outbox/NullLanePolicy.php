<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Priority\PriorityLanePolicy;

/**
 * The no-lane `SagaLanePolicy`: every saga-issued command keeps its normal class routing, with no
 * priority lane. It is the publisher's zero-config default and the standalone or test fallback; a real
 * deployment wires a configured {@see PriorityLanePolicy}.
 */
final readonly class NullLanePolicy implements SagaLanePolicy
{
    public function laneFor(object $command, string $workflowType): null
    {
        return null;
    }
}
