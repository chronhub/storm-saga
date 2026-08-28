<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Priority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\Prioritized;
use Storm\Saga\Priority\AttributePriorityResolver;

/**
 * Precedence: the command's `#[Prioritized]` level wins over the per-workflow default, which wins over
 * the global default; with none of the three, the resolution is null, so no priority and no lane.
 */
final class AttributePriorityResolverTest extends TestCase
{
    #[Test]
    public function the_command_attribute_wins_over_workflow_and_global(): void
    {
        $resolver = new AttributePriorityResolver(default: 10, perWorkflow: ['transfer' => 20]);
        $command = new #[Prioritized(40)] class {};

        $this->assertSame(40, $resolver->resolve($command, 'transfer'));
    }

    #[Test]
    public function the_per_workflow_default_applies_when_the_command_is_unmarked(): void
    {
        $resolver = new AttributePriorityResolver(default: 10, perWorkflow: ['transfer' => 20]);
        $command = new class() {};

        $this->assertSame(20, $resolver->resolve($command, 'transfer'));
    }

    #[Test]
    public function the_global_default_applies_when_neither_attribute_nor_workflow_match(): void
    {
        $resolver = new AttributePriorityResolver(default: 10, perWorkflow: ['transfer' => 20]);
        $command = new class() {};

        $this->assertSame(10, $resolver->resolve($command, 'card'));
    }

    #[Test]
    public function nothing_assigned_anywhere_resolves_to_null(): void
    {
        $resolver = new AttributePriorityResolver;
        $command = new class() {};

        $this->assertNull($resolver->resolve($command, 'transfer'));
    }

    #[Test]
    public function an_unmarked_command_resolves_consistently_across_calls(): void
    {
        // exercises the per-class cache hit path: a null result is cached, not re-reflected each call.
        $resolver = new AttributePriorityResolver(default: 10);
        $command = new class() {};

        $this->assertSame(10, $resolver->resolve($command, 'transfer'));
        $this->assertSame(10, $resolver->resolve($command, 'transfer'));
    }
}
