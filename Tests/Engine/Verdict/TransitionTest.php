<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\Verdict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Verdict\Transition;

final class TransitionTest extends TestCase
{
    #[Test]
    public function a_bare_transition_is_not_degraded(): void
    {
        // the default IS the contract: only an explicit fallback salvage flags degraded; a wait's
        // event/timeout transition, built without the flag, must never be born degraded
        $transition = new Transition('next', OnTrigger::Event);

        $this->assertFalse($transition->degraded);
        $this->assertSame([], $transition->commands);
    }
}
