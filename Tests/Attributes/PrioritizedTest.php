<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Attributes;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\Prioritized;
use Storm\Saga\Tests\Priority\TestLane;

/**
 * The attribute carries an opaque level plus an optional observability label. A typed `Priority` yields
 * its level and the enum case name as a free label; a bare int yields the level with no label unless
 * one is given; an explicit name always wins.
 */
final class PrioritizedTest extends TestCase
{
    #[Test]
    public function a_typed_priority_takes_its_level_and_the_case_name_as_a_free_label(): void
    {
        $p = new Prioritized(TestLane::Capture);

        $this->assertSame(40, $p->level);
        $this->assertSame('Capture', $p->name);
    }

    #[Test]
    public function a_bare_int_takes_the_level_with_no_label(): void
    {
        $p = new Prioritized(40);

        $this->assertSame(40, $p->level);
        $this->assertNull($p->name);
    }

    #[Test]
    public function a_bare_int_keeps_an_explicit_label(): void
    {
        $p = new Prioritized(40, 'capture');

        $this->assertSame(40, $p->level);
        $this->assertSame('capture', $p->name);
    }

    #[Test]
    public function an_explicit_label_overrides_the_enum_case_name(): void
    {
        $p = new Prioritized(TestLane::Capture, 'override');

        $this->assertSame(40, $p->level);
        $this->assertSame('override', $p->name);
    }
}
