<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\InvalidMetadata;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Workflow\Metadata;

final class MetadataTest extends TestCase
{
    #[Test]
    public function builds_with_valid_identifiers(): void
    {
        $meta = new Metadata('payment', 'corr-1', 'cause-1', ['actor' => 'u1']);

        $this->assertSame('payment', $meta->workflowType);
        $this->assertSame('corr-1', $meta->correlationId);
        $this->assertSame('cause-1', $meta->causationId);
        $this->assertSame(['actor' => 'u1'], $meta->enrichedContext);
    }

    #[Test]
    public function causation_id_is_optional(): void
    {
        $this->assertNull(new Metadata('payment', 'corr-1')->causationId);
    }

    #[Test]
    public function rejects_an_empty_workflow_type(): void
    {
        $this->expectException(InvalidMetadata::class);
        $this->expectExceptionMessageIsOrContains('workflowType cannot be empty.');
        new Metadata('  ', 'corr-1');
    }

    #[Test]
    public function rejects_an_empty_correlation_id(): void
    {
        $this->expectException(InvalidMetadata::class);
        new Metadata('payment', '');
    }

    #[Test]
    public function rejects_an_empty_causation_id_string(): void
    {
        // an empty causationId is a bug; use null to mean "no parent"
        $this->expectException(InvalidMetadata::class);
        new Metadata('payment', 'corr-1', '');
    }

    #[Test]
    public function rejects_an_identifier_over_the_byte_cap(): void
    {
        $this->expectException(InvalidMetadata::class);
        $this->expectExceptionMessageIsOrContains('got 513');
        new Metadata('payment', str_repeat('x', WorkflowId::MAX_BYTES + 1));
    }

    #[Test]
    public function accepts_an_identifier_at_exactly_the_cap(): void
    {
        // the boundary is inclusive: the guard is `> MAX_BYTES`, not `>=`
        $id = str_repeat('x', WorkflowId::MAX_BYTES);
        $this->assertSame($id, new Metadata('payment', $id)->correlationId);
    }

    #[Test]
    public function counts_bytes_not_characters(): void
    {
        // 256 two-byte characters: 256 characters, 512 bytes. Bytes are what the b-tree index counts,
        // so bytes are what the cap counts; this value is at the cap, not half of it.
        $id = str_repeat('é', 256);
        $this->assertSame(512, strlen($id));
        $this->assertSame($id, new Metadata('payment', $id)->correlationId);

        $this->expectException(InvalidMetadata::class);
        $this->expectExceptionMessageIsOrContains('got 514');
        new Metadata('payment', str_repeat('é', 257));
    }

    #[Test]
    public function rejects_a_nul_that_storage_would_truncate_away(): void
    {
        // measured: the DBAL/PostgreSQL text path stores "corr-a\0b" as "corr-a". Two distinct ids would
        // converge on one row, and for a saga that means one instance answering for another.
        $this->expectException(InvalidMetadata::class);
        $this->expectExceptionMessageIsOrContains('control or format character');
        new Metadata('payment', "corr-a\0b");
    }

    #[Test]
    public function accepts_the_reserved_child_delimiter(): void
    {
        // \x1f is a control character the module WRITES itself: a child's correlation is minted as
        // parent + delimiter + slot, so the guard must not refuse the module's own identities.
        $child = 'parent-1'.ChildCorrelation::DELIMITER.'review';

        $this->assertSame($child, new Metadata('payment', $child)->correlationId);
    }
}
