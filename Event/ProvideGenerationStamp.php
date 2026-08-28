<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * The one mutation an announcement admits: re-stamping its run number. Lives in a trait because a
 * readonly property is only clone-writable from inside its own class scope, the same reason the
 * instance row's `claimedAs()` is a method; the birth path cannot clone announcements from
 * outside, so it asks them.
 */
trait ProvideGenerationStamp
{
    public function withGeneration(int $generation): static
    {
        return clone ($this, ['generation' => $generation]);
    }
}
