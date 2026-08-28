<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Cadence;

/**
 * The deterministic per-instance phase offset of a spread schedule: `crc32(correlationId) % spread`. A
 * pure function of the instance's identity, with no state and no persistence, replay-safe since the same
 * instance always lands on the same offset, and stable across platforms since crc32 is IEEE 802.3.
 * Deliberately not random: the engine forbids non-determinism, and a re-derived offset must match the one
 * every previously armed timer was computed with.
 *
 * @see SpreadCadence the translation the offset feeds
 */
final class SpreadOffset
{
    /**
     * The instance's offset within `[0, $spreadSeconds)`. Uniform enough to flatten a fleet born in a
     * burst; zero means the instance happens to sit exactly on the unshifted grid.
     *
     * @param  int<1, max>  $spreadSeconds
     * @return int<0, max>
     */
    public static function for(string $correlationId, int $spreadSeconds): int
    {
        // @infection-ignore-all; defensive floor: crc32 is non-negative on 64-bit PHP, the runtime this
        // targets; the floor only guards a 32-bit build, where crc32 can overflow negative
        return max(0, crc32($correlationId) % $spreadSeconds);
    }
}
