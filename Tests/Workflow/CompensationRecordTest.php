<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;

final class CompensationRecordTest extends TestCase
{
    #[Test]
    public function a_bare_record_defaults_to_unconfirmed_and_not_degraded(): void
    {
        $record = new CompensationRecord('charge', CompensationStatus::Pending);

        $this->assertFalse($record->confirmed);
        $this->assertFalse($record->degraded);
        $this->assertNull($record->reason);
        $this->assertNull($record->at);
    }

    #[Test]
    public function a_minimal_stored_payload_hydrates_with_safe_defaults(): void
    {
        // a pre-flags row must come back unconfirmed and not degraded; the
        // SAFE side of both eligibility rules, never the permissive one
        $record = CompensationRecord::fromArray(['step' => 'charge', 'status' => 'pending']);

        $this->assertFalse($record->confirmed);
        $this->assertFalse($record->degraded);
        $this->assertNull($record->reason);
        $this->assertNull($record->at);
    }

    #[Test]
    public function a_full_stored_payload_hydrates_both_flags_true(): void
    {
        // the inverse of the minimal-payload test: present flags must be READ, not defaulted
        $record = CompensationRecord::fromArray(['step' => 'charge', 'status' => 'pending', 'confirmed' => true, 'degraded' => true]);

        $this->assertTrue($record->confirmed);
        $this->assertTrue($record->degraded);
    }

    #[Test]
    public function confirm_keeps_the_original_timestamp_when_none_is_given(): void
    {
        $record = CompensationRecord::pending('charge', '2026-06-11T10:00:00Z');

        $this->assertSame('2026-06-11T10:00:00Z', $record->confirm()->at);
        $this->assertSame('2026-06-11T11:00:00Z', $record->confirm('2026-06-11T11:00:00Z')->at);
    }

    #[Test]
    public function settle_keeps_the_original_timestamp_when_none_is_given(): void
    {
        $record = CompensationRecord::pending('charge', '2026-06-11T10:00:00Z');

        $settled = $record->settle(CompensationStatus::Skipped, 'unconfirmed');
        $this->assertSame('2026-06-11T10:00:00Z', $settled->at);
        $this->assertSame('unconfirmed', $settled->reason);

        $this->assertSame('2026-06-11T11:00:00Z', $record->settle(CompensationStatus::Failed, 'boom', '2026-06-11T11:00:00Z')->at);
    }

    #[Test]
    public function to_array_carries_every_field_for_the_jsonb_column(): void
    {
        // the persisted shape: each key pinned so a dropped/swapped binding is caught, and fromArray()
        // can round-trip it: the compensations column the instance store serializes.
        $record = new CompensationRecord('charge', CompensationStatus::Skipped, true, true, 'unconfirmed', '2026-06-11T10:00:00Z');

        $this->assertSame([
            'step' => 'charge',
            'status' => 'skipped',
            'confirmed' => true,
            'degraded' => true,
            'reason' => 'unconfirmed',
            'at' => '2026-06-11T10:00:00Z',
        ], $record->toArray());
    }
}
