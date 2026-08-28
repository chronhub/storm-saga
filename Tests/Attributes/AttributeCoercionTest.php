<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Attributes;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\CircuitBreaker;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Tests\Priority\TestLane;
use Storm\Saga\Workflow\BackoffStrategy;
use ValueError;

/**
 * The attribute DSL accepts both the string form, there for authoring convenience, and the enum form,
 * coercing the string to the enum.
 */
final class AttributeCoercionTest extends TestCase
{
    #[Test]
    public function state_coerces_the_type_string_to_the_enum(): void
    {
        $this->assertSame(StateType::Activity, new State('charge', 'activity')->type);
        $this->assertSame(StateType::Wait, new State('await', StateType::Wait)->type);
    }

    #[Test]
    public function on_coerces_the_trigger_string_to_the_enum(): void
    {
        $on = new On('charge', 'success', 'confirm');

        $this->assertSame(OnTrigger::Success, $on->trigger);
        $this->assertSame('charge', $on->from);
        $this->assertSame('confirm', $on->to);
    }

    #[Test]
    public function retry_coerces_the_strategy_string_to_the_enum(): void
    {
        $this->assertSame(BackoffStrategy::Fixed, new Retry('charge', strategy: 'fixed')->strategy);
        $this->assertSame(BackoffStrategy::Exponential, new Retry('charge')->strategy); // default
    }

    #[Test]
    public function wait_for_normalises_a_single_event_to_a_list(): void
    {
        $this->assertSame(['App\\Approved'], new WaitFor('await', 'App\\Approved')->events);
        $this->assertSame(['a', 'b'], new WaitFor('await', ['a', 'b'])->events);
        $this->assertSame(['a', 'b'], new WaitFor('await', [5 => 'a', 9 => 'b'])->events); // a non-list array is re-indexed to a list
    }

    #[Test]
    public function wait_for_coerces_a_typed_priority_lane_to_its_level(): void
    {
        $this->assertSame(40, new WaitFor('await', 'App\\Approved', lane: TestLane::Capture)->lane);
        $this->assertSame(40, new WaitFor('await', 'App\\Approved', lane: 40)->lane);
        $this->assertNull(new WaitFor('await', 'App\\Approved')->lane); // default: the default signal lane
    }

    #[Test]
    public function retry_has_sensible_defaults(): void
    {
        $retry = new Retry('charge');

        $this->assertSame(0, $retry->maxAttempts); // no retry unless asked for
        $this->assertSame(500, $retry->baseMs);
        $this->assertTrue($retry->jitter);
    }

    #[Test]
    public function circuit_breaker_has_sensible_defaults(): void
    {
        $cb = new CircuitBreaker('charge', 'payment-gateway');

        $this->assertSame(5, $cb->failureThreshold);
        $this->assertSame(30, $cb->cooldownSeconds);
    }

    #[Test]
    public function an_invalid_type_string_throws(): void
    {
        $this->expectException(ValueError::class);
        new State('charge', 'nope');
    }
}
