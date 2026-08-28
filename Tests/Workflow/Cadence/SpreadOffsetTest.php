<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow\Cadence;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Workflow\Cadence\SpreadOffset;

final class SpreadOffsetTest extends TestCase
{
    #[Test]
    public function the_offset_is_pinned_to_the_crc32_of_the_identity(): void
    {
        // a LITERAL pin, not a re-derivation: every armed timer in every store was computed with
        // crc32 % spread; swapping the hash function would silently shift every live grid. This
        // test is the tripwire: crc32('corr-1') = 3279401527, % 3600 = 3127.
        self::assertSame(3127, SpreadOffset::for('corr-1', 3600));
    }

    #[Test]
    public function the_offset_is_deterministic_for_an_instance(): void
    {
        self::assertSame(SpreadOffset::for('mandate-42', 1800), SpreadOffset::for('mandate-42', 1800));
    }

    #[Test]
    public function the_offset_stays_within_the_spread(): void
    {
        foreach (['a', 'mandate-1', 'mandate-2', str_repeat('x', 200)] as $id) {
            $offset = SpreadOffset::for($id, 60);
            self::assertGreaterThanOrEqual(0, $offset);
            self::assertLessThan(60, $offset);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_batch_born_fleet_lands_on_distinct_offsets(): void
    {
        // the whole point: identities minted together, sequential ids of the mass-migration shape,
        // must NOT collapse onto a handful of slots. Pins the spreading property, not uniformity.
        $offsets = [];
        for ($i = 0; $i < 100; $i++) {
            $offsets[SpreadOffset::for("mandate-{$i}", 1800)] = true;
        }

        self::assertGreaterThan(90, count($offsets)); // ~uniform: near-zero collisions over 100/1800
    }
}
