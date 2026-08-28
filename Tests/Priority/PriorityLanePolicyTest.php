<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Priority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Priority\PriorityLanePolicy;
use Storm\Saga\Priority\PriorityResolver;

/**
 * The policy joins a resolved level to a lane through the app's level-to-transport table: a mapped level
 * gives its transport, an unmapped level or a null resolution gives no lane and keeps the class routing.
 */
final class PriorityLanePolicyTest extends TestCase
{
    #[Test]
    public function a_resolved_level_maps_to_its_lane(): void
    {
        $policy = new PriorityLanePolicy($this->resolving(40), [40 => 'async_critical', 30 => 'async_priority']);

        $this->assertSame('async_critical', $policy->laneFor(new stdClass, 'transfer'));
    }

    #[Test]
    public function a_level_with_no_mapped_lane_yields_no_lane(): void
    {
        $policy = new PriorityLanePolicy($this->resolving(99), [40 => 'async_critical']);

        $this->assertNull($policy->laneFor(new stdClass, 'transfer'));
    }

    #[Test]
    public function no_resolved_priority_yields_no_lane(): void
    {
        $policy = new PriorityLanePolicy($this->resolving(null), [40 => 'async_critical']);

        $this->assertNull($policy->laneFor(new stdClass, 'transfer'));
    }

    private function resolving(?int $level): PriorityResolver
    {
        return new readonly class($level) implements PriorityResolver
        {
            public function __construct(private ?int $level) {}

            public function resolve(object $command, string $workflowType): ?int
            {
                return $this->level;
            }
        };
    }
}
