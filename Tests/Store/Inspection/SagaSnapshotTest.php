<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store\Inspection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\Inspection\SagaSummarySnapshot;

/**
 * The inspection DTO's tail defaults, which only a caller that omits them ever reads.
 *
 * Every production builder fills the whole shape, so the defaults are exercised by the operator
 * surfaces alone: a snapshot assembled from a row that predates a field, or hand-built by a console
 * rig. They have to describe a saga that never used the feature, not one that used it once.
 */
final class SagaSnapshotTest extends TestCase
{
    #[Test]
    public function a_snapshot_built_without_its_tail_describes_a_saga_that_used_none_of_it(): void
    {
        $snapshot = new SagaSnapshot(
            workflowType: 'payment',
            stateKey: 'charge',
            status: 'running',
            version: 3,
            startedAt: null,
            updatedAt: null,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            waivedAt: null,
            retries: [],
            compensations: [],
            timers: [],
            outbox: [],
        );

        // an unversioned bag is version ONE, never a bag that has already migrated once
        $this->assertSame(1, $snapshot->stateVersion);
        // and no deadline has been negotiated yet, so the budget reads as untouched
        $this->assertSame(0, $snapshot->retimes);
        $this->assertSame([], $snapshot->exposed);
        $this->assertSame([], $snapshot->children);
        $this->assertNull($snapshot->pausedAt);
        // the WIDER hold, and the one the list above kept missing: a type freeze carries no stamp on
        // this row, so a default reading true would make every inspected saga claim a freeze it has not
        $this->assertFalse($snapshot->typePaused);
    }

    #[Test]
    public function a_summary_built_without_its_tail_reads_as_unfrozen_too(): void
    {
        // the other side of the pair: the listing row carries the same flag with the same default, and
        // the console renders a `paused(type)` line off it. Two classes, one contract, and only one of
        // them had a default to answer for.
        $summary = new SagaSummarySnapshot(
            workflowType: 'payment',
            correlationId: 'c-1',
            stateKey: 'charge',
            status: 'running',
            version: 3,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            startedAt: null,
            updatedAt: null,
            waivedAt: null,
            parentCorrelationId: null,
        );

        $this->assertFalse($summary->typePaused);
    }
}
