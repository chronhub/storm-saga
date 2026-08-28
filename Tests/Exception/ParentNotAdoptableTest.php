<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Exception\ParentNotAdoptable;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The refusal a child's birth raises when its declared parent cannot adopt it. Both halves matter and
 * are asserted here: the `reason`, which the spawner's skip announces, and the MESSAGE, which is the
 * only thing a human sees in a dead-letter; an exception that reaches the DLQ with an empty message
 * says nothing about which child, which parent, or why.
 */
final class ParentNotAdoptableTest extends TestCase
{
    #[Test]
    public function a_missing_parent_names_both_correlations_and_its_reason(): void
    {
        $e = ParentNotAdoptable::missing('child-1', 'parent-9');

        $this->assertSame('parent-missing', $e->reason);
        $this->assertStringContainsString('child-1', $e->getMessage());
        $this->assertStringContainsString('parent-9', $e->getMessage());
    }

    #[Test]
    public function a_terminal_parent_names_the_status_that_makes_it_unadoptable(): void
    {
        // "which is completed" is the half that tells an operator this was a race with the settle, not
        // a missing row; the two have different fixes
        $e = ParentNotAdoptable::terminal('child-2', 'parent-9', WorkflowStatus::Completed);

        $this->assertStringContainsString('completed', $e->getMessage());
        $this->assertStringContainsString('child-2', $e->getMessage());
    }
}
