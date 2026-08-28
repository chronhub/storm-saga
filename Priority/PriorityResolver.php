<?php

declare(strict_types=1);

namespace Storm\Saga\Priority;

use Storm\Saga\Attributes\Prioritized;

/**
 * Resolves the priority LEVEL a saga-issued command rides at: the per-leg override, a {@see Prioritized}
 * on the command, else the issuing workflow's default, else a global default. Returns null when nothing
 * assigns a priority, so the command keeps its class routing with no lane.
 *
 * @see PriorityLanePolicy maps the resolved level to a lane
 */
interface PriorityResolver
{
    /**
     * The resolved priority level for `$command` issued by `$workflowType`, or null when none applies.
     */
    public function resolve(object $command, string $workflowType): ?int;
}
