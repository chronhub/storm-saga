<?php

declare(strict_types=1);

namespace Storm\Saga\Priority;

use Storm\Saga\Attributes\Prioritized;

/**
 * The urgency of a saga-issued command, as an opaque ordinal; higher is more urgent. Storm names no
 * levels and caps no cardinality: a consumer brings its OWN vocabulary by implementing this, typically a
 * backed enum such as `enum Lane: int implements Priority`, and storm only ever reads `level()` to map it
 * to a lane. Which level maps to which transport is the app's config, not storm's concern.
 *
 * @see Prioritized the attribute that carries a priority onto a command
 * @see PriorityResolver resolves the level a command rides at
 */
interface Priority
{
    /**
     * The ordinal urgency; higher is more urgent.
     */
    public function level(): int;
}
