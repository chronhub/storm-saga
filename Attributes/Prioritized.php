<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Priority\Priority;
use Storm\Saga\Priority\PriorityResolver;
use UnitEnum;

/**
 * Declares the urgency of a saga-issued command, a per-leg override of the workflow or global default. The
 * resolver's precedence is this attribute first, then the issuing workflow's default, then the global
 * default. The level is an opaque ordinal where higher is more urgent; storm maps it to a lane, it does
 * not name it.
 *
 * One attribute, two hosts. On a COMMAND class the level is the leg's own urgency, intrinsic to the
 * gesture wherever it is issued from; it is the only host that follows a command SHARED across workflows,
 * and is read by the resolver at dispatch. On a `#[Workflow]` class it is that workflow's default for
 * every command it issues that carries no attribute of its own, compiled at container build into the
 * resolver's per-workflow map; the code is the single source of a workflow's posture, config carries
 * only the level-to-transport realization.
 *
 * Pass either a typed {@see Priority}, such as an app `enum Lane: int implements Priority` where
 * `#[Prioritized(Lane::Urgent)]` reads the level and takes the case name as a free label, or a bare int
 * with an optional label such as `#[Prioritized(40, 'urgent')]`. The label is observability metadata for
 * telemetry and ops, not part of routing: only `level` selects the lane.
 *
 * @see PriorityResolver
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Prioritized
{
    /** The ordinal urgency used to map the command to a lane; higher is more urgent. */
    public int $level;

    /** Optional human label for observability; defaults to the enum case name when a Priority enum is passed. */
    public ?string $name;

    public function __construct(Priority|int $priority, ?string $name = null)
    {
        $this->level = $priority instanceof Priority ? $priority->level() : $priority;
        $this->name = $name ?? ($priority instanceof UnitEnum ? $priority->name : null);
    }
}
