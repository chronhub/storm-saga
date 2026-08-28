<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;

final class CatchUpPolicyTest extends TestCase
{
    #[Test]
    public function skip_collapses_a_gap_to_one_tick(): void
    {
        self::assertSame(1, new CatchUpPolicy(CatchUp::Skip)->ticksFor(5));
    }

    #[Test]
    public function bounded_caps_at_the_limit(): void
    {
        $policy = new CatchUpPolicy(CatchUp::ReplayBounded, limit: 3);

        self::assertSame(3, $policy->ticksFor(5)); // more due than the limit, capped
        self::assertSame(2, $policy->ticksFor(2)); // fewer due than the limit, all of them
    }

    #[Test]
    public function replay_all_fires_every_due_tick(): void
    {
        self::assertSame(7, new CatchUpPolicy(CatchUp::ReplayAll)->ticksFor(7));
    }

    #[Test]
    public function the_tick_count_is_never_below_one(): void
    {
        // a degenerate zero-due never silently fires nothing
        self::assertSame(1, new CatchUpPolicy(CatchUp::ReplayAll)->ticksFor(0));
    }

    #[Test]
    public function replay_all_is_ceilinged_at_the_backstop(): void
    {
        self::assertSame(
            CatchUpPolicy::MAX_TICKS,
            new CatchUpPolicy(CatchUp::ReplayAll)->ticksFor(CatchUpPolicy::MAX_TICKS + 5000),
        );
    }

    #[Test]
    public function only_replay_all_with_a_window_imposes_an_eligibility_floor(): void
    {
        $now = PointInTime::from('2026-06-15T12:00:00.000000Z');

        self::assertSame(
            '2026-06-15T11:00:00.000000+00:00',
            new CatchUpPolicy(CatchUp::ReplayAll, windowSeconds: 3600)->windowFloor($now)?->toString(),
        );
        self::assertNull(new CatchUpPolicy(CatchUp::ReplayAll)->windowFloor($now)); // ReplayAll, no window
        self::assertNull(new CatchUpPolicy(CatchUp::Skip, windowSeconds: 3600)->windowFloor($now)); // window, not ReplayAll
    }
}
