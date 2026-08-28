<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\InvalidWorkflowId;
use Storm\Saga\Store\WorkflowId;

/**
 * The identity frontier every saga WRITE crosses. Both rules exist because storage fails in a way the
 * engine cannot see: past the byte cap the INSERT dies inside a step on an index-row-size error naming
 * neither saga nor value, and a control character is truncated away in SILENCE.
 */
final class WorkflowIdTest extends TestCase
{
    #[Test]
    public function carries_a_plain_identity_untouched(): void
    {
        $id = new WorkflowId('payment', 'order-42');

        $this->assertSame('payment', $id->workflowType);
        $this->assertSame('order-42', $id->correlationId);
    }

    #[Test]
    public function refuses_a_blank_id(): void
    {
        $this->expectException(InvalidWorkflowId::class);

        new WorkflowId('payment', '   ');
    }

    #[Test]
    public function refuses_a_workflow_type_as_readily_as_a_correlation(): void
    {
        // both halves of the key are guarded: the type is declared by a dev, but it reaches the same
        // indexed columns, and a guard that trusts one half is not a guard
        $this->expectException(InvalidWorkflowId::class);

        new WorkflowId('', 'order-42');
    }

    #[Test]
    #[Group('adversarial')]
    public function refuses_a_nul_that_storage_would_truncate_away(): void
    {
        // MEASURED against the real driver: "order-a\0b" and "order-a\0c" both store as "order-a".
        // The correlation is UNIQUE and the outcome router resolves an instance from it alone, so the
        // two would collapse onto ONE row, and one saga would start answering for the other, the same
        // shape of cross-talk the correlation registry closes between generations.
        $this->expectException(InvalidWorkflowId::class);
        $this->expectExceptionMessageIsOrContains('converge on one stored row');

        new WorkflowId('payment', "order-a\0b");
    }

    #[Test]
    public function refuses_other_control_characters_too(): void
    {
        // not only the NUL: a newline or a zero-width joiner in an id makes two identities that look
        // identical in every log and console this framework prints
        $this->expectException(InvalidWorkflowId::class);

        new WorkflowId('payment', "order\n42");
    }

    #[Test]
    public function accepts_the_reserved_child_delimiter(): void
    {
        // the one control character allowed, because the module writes it ITSELF: a child's correlation
        // is minted as parent + delimiter + slot. A blanket "no control characters" rule would refuse
        // every child workflow the engine creates.
        $child = ChildCorrelation::mint('parent-1', 'review');

        $this->assertSame($child, new WorkflowId('payment', $child)->correlationId);
    }

    #[Test]
    public function caps_the_byte_length_at_the_boundary(): void
    {
        $this->assertSame(
            str_repeat('x', WorkflowId::MAX_BYTES),
            new WorkflowId('payment', str_repeat('x', WorkflowId::MAX_BYTES))->correlationId,
        );

        $this->expectException(InvalidWorkflowId::class);
        $this->expectExceptionMessageIsOrContains('got 513');

        new WorkflowId('payment', str_repeat('x', WorkflowId::MAX_BYTES + 1));
    }

    #[Test]
    public function two_capped_ids_stay_under_the_btree_row_limit(): void
    {
        // the cap's whole justification, kept honest here rather than only in prose: the widest index a
        // saga id lands in is the timers' four-column unique, and PostgreSQL refuses a b-tree row over
        // 2 704 bytes. Two ids at the cap, plus a declared state key and kind, must fit with room.
        $widest = 2 * WorkflowId::MAX_BYTES + strlen('some_reasonably_long_state_key') + strlen('timeout');

        $this->assertLessThan(2704, $widest);
    }
}
