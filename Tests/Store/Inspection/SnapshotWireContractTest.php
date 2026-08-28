<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store\Inspection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Store\Inspection\ChildSnapshot;
use Storm\Saga\Store\Inspection\OutboxSnapshot;
use Storm\Saga\Store\Inspection\TimerSnapshot;

/**
 * The snake_case shapes the inspection DTOs serve, asserted whole rather than field by field.
 *
 * These arrays ARE the contract: the console `--json` and the ops HTTP surface both render this and
 * nothing else, so a renamed key or a value read off the wrong property reaches a script as a silent
 * change of format. Comparing the entire array is what makes a dropped key fail, which a per-field
 * assertion cannot see.
 */
final class SnapshotWireContractTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_timer_serves_every_key_of_its_shape_from_the_property_that_owns_it(): void
    {
        $timer = new TimerSnapshot(
            id: 7,
            kind: 'deadline',
            stateKey: 'await_capture',
            fireAt: '2026-01-01T10:00:00.000000+00:00',
            claimedAt: '2026-01-01T09:59:00.000000+00:00',
            attempts: 2,
            parkedAt: '2026-01-01T10:05:00.000000+00:00',
            lastError: 'broker refused',
        );

        // every value distinct on purpose: two fields sharing one would let a mutant read the wrong
        // property and still produce the expected array
        $this->assertSame([
            'id' => 7,
            'kind' => 'deadline',
            'state_key' => 'await_capture',
            'fire_at' => '2026-01-01T10:00:00.000000+00:00',
            'claimed_at' => '2026-01-01T09:59:00.000000+00:00',
            'attempts' => 2,
            'parked_at' => '2026-01-01T10:05:00.000000+00:00',
            'last_error' => 'broker refused',
        ], $timer->toArray());
    }

    #[Test]
    public function a_timer_that_never_ran_carries_no_attempt_no_park_and_no_error(): void
    {
        // the tail an operator surface builds when a row predates those fields: it must describe a
        // timer that has done NOTHING, never one that already tried once
        $timer = new TimerSnapshot(
            id: 1,
            kind: 'timeout',
            stateKey: 'await_hold',
            fireAt: '2026-01-01T10:00:00.000000+00:00',
            claimedAt: null,
        );

        $this->assertSame(0, $timer->attempts);
        $this->assertNull($timer->parkedAt);
        $this->assertNull($timer->lastError);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outbox_entry_serves_every_key_of_its_shape_from_the_property_that_owns_it(): void
    {
        $entry = new OutboxSnapshot(
            status: 'failed',
            command: 'App\\CaptureFunds',
            bus: 'command.bus',
            attempts: 3,
            createdAt: '2026-01-01T10:00:00.000000+00:00',
            lastError: 'transport down',
            issuedFromState: 'capture',
            issuedAtVersion: 12,
            generation: 2,
            messageId: 'mid-1',
            evidence: EffectEvidence::Uncommitted,
            effectGroup: 'capture-group',
        );

        $this->assertSame([
            'status' => 'failed',
            'command' => 'App\\CaptureFunds',
            'bus' => 'command.bus',
            'attempts' => 3,
            'created_at' => '2026-01-01T10:00:00.000000+00:00',
            'last_error' => 'transport down',
            'issued_from_state' => 'capture',
            'issued_at_version' => 12,
            'generation' => 2,
            'message_id' => 'mid-1',
            'evidence' => 'uncommitted',
            'effect_group' => 'capture-group',
        ], $entry->toArray());
    }

    #[Test]
    public function an_outbox_entry_built_without_its_tail_claims_no_evidence(): void
    {
        // the safe side of the eligibility rule: an entry whose provenance was never established
        // reads UNKNOWN, so a redrive has to prove what happened rather than assume nothing did
        $entry = new OutboxSnapshot(
            status: 'pending',
            command: null,
            bus: 'command.bus',
            attempts: 0,
            createdAt: '2026-01-01T10:00:00.000000+00:00',
            lastError: null,
            issuedFromState: 'charge',
            issuedAtVersion: 1,
            generation: 1,
        );

        $this->assertNull($entry->messageId);
        $this->assertSame(EffectEvidence::Unknown, $entry->evidence);
        $this->assertNull($entry->effectGroup);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_child_serves_every_key_of_its_shape_from_the_property_that_owns_it(): void
    {
        $child = new ChildSnapshot(
            workflowType: 'refund',
            correlationId: 'c-9',
            status: 'completed',
        );

        $this->assertSame([
            'workflow_type' => 'refund',
            'correlation_id' => 'c-9',
            'status' => 'completed',
        ], $child->toArray());
    }
}
