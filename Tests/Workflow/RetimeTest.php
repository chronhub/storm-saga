<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Workflow\Retime;
use ValueError;

final class RetimeTest extends TestCase
{
    #[Test]
    public function extend_carries_its_seconds_and_restart_carries_none(): void
    {
        $this->assertSame(172_800, Retime::extend(172_800)->seconds);
        $this->assertSame(1, Retime::extend(1)->seconds); // the floor is one second, and it is granted, not refused
        $this->assertNull(Retime::restart()->seconds); // null IS the restart form: the wait's own declared deadline
    }

    #[Test]
    #[Group('adversarial')]
    public function a_non_positive_extension_is_refused_at_the_factory(): void
    {
        // the shell floors an armed timeout to 1s, so a negative slipping through would not fail;
        // it would quietly COLLAPSE the deadline to now, an instant expiry wearing an extension's
        // name; the refusal belongs here, at the handler's own call site
        $this->expectException(ValueError::class);
        $this->expectExceptionMessageMatches('/positive number of seconds, got -5/');

        Retime::extend(-5);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_zero_extension_is_refused_too(): void
    {
        $this->expectException(ValueError::class);

        Retime::extend(0);
    }
}
