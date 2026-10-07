<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Outbox;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\ContextValues;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;

/**
 * The front's two duties: a written command reaches the port ALREADY sealed, the structural
 * guarantee the front exists for, and the recall forwards untouched. The protocol's clauses
 * live in HopProtocolTest; here only the composition is pinned.
 */
final class WorkflowOutboxTest extends TestCase
{
    #[Test]
    public function write_hands_the_port_the_sealed_message(): void
    {
        $writer = $this->capturingWriter();
        $command = new stdClass;
        $id = new WorkflowId('transfer', 't-1');

        new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->write($id, $command, 'credit', 3, 1, CommandPurpose::Compensation);

        $this->assertCount(1, $writer->written);
        [$writtenId, $message, $fromState, $atVersion, , $purpose] = $writer->written[0];
        $this->assertSame($id, $writtenId);
        $this->assertSame($command, $message->message());
        $this->assertSame('credit', $fromState); // the provenance rides through untouched
        $this->assertSame(3, $atVersion);
        $this->assertSame(CommandPurpose::Compensation, $purpose); // and so does the purpose, never defaulted on the way
        // sealed BEFORE storage: the protocol's mandatory trio is on the message the port received
        $this->assertSame(stdClass::class, $message->header(Header::MessageType));
        $this->assertSame('t-1', $message->header(Header::CorrelationId));
        $this->assertNotNull($message->messageId());
    }

    #[Test]
    public function cancel_pending_forwards_the_ports_count(): void
    {
        $writer = $this->capturingWriter();
        $id = new WorkflowId('transfer', 't-1');

        $undone = CompensationRecord::pending('quote')->settle(CompensationStatus::Compensated);

        $recalled = new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->cancelPending($id, 2, [$undone]);

        $this->assertSame(7, $recalled);
        $this->assertSame([[$id, 2, [$undone]]], $writer->cancelled);
    }

    #[Test]
    public function recall_undispatched_forwards_the_ports_count(): void
    {
        $writer = $this->capturingWriter();
        $id = new WorkflowId('transfer', 't-1');

        $recalled = new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->recallUndispatched($id, 2, 'quote', 'beta');

        $this->assertSame(3, $recalled);
        $this->assertSame([[$id, 2, 'quote', 'beta']], $writer->recalled);
    }

    #[Test]
    public function forward_claims_forwards_the_ports_flags(): void
    {
        $writer = $this->capturingWriter();
        $id = new WorkflowId('transfer', 't-1');

        $claims = new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->forwardClaims($id, 2, 'quote', null);

        $this->assertSame([false, true], $claims);
        $this->assertSame([[$id, 2, 'quote', null]], $writer->read);
    }

    /**
     * @return WorkflowOutboxWriter&object{written: list<array{WorkflowId, Message, string, int, int, CommandPurpose}>, cancelled: list<array{WorkflowId, int, list<CompensationRecord>}>, recalled: list<array{WorkflowId, int, string, string}>, read: list<array{WorkflowId, int, string, string|null}>}
     */
    private function capturingWriter(): WorkflowOutboxWriter
    {
        return new class() implements WorkflowOutboxWriter
        {
            /** @var list<array{WorkflowId, Message, string, int, int, CommandPurpose}> */
            public array $written = [];

            /** @var list<array{WorkflowId, int, list<CompensationRecord>}> */
            public array $cancelled = [];

            /** @var list<array{WorkflowId, int, string, string}> */
            public array $recalled = [];

            /** @var list<array{WorkflowId, int, string, string|null}> */
            public array $read = [];

            public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void
            {
                $this->written[] = [$id, $message, $issuedFromState, $issuedAtVersion, $generation, $purpose];
            }

            public function provenance(string $correlationId, string $messageId, int $generation = 1): ?EffectProvenance
            {
                return null; // unused by these specs; the seal/recall composition is what is pinned here
            }

            public function markFailed(string $correlationId, string $messageId, string $error, EffectEvidence $evidence = EffectEvidence::Unknown): bool
            {
                return false; // unused by these specs; the seal/recall composition is what is pinned here
            }

            public function redrive(string $correlationId, string $messageId, bool $force = false): RedriveOutcome
            {
                return RedriveOutcome::NotFound; // unused by these specs; the seal/recall composition is what is pinned here
            }

            public function cancelPending(WorkflowId $id, int $generation, array $spared): int
            {
                $this->cancelled[] = [$id, $generation, $spared];

                return 7;
            }

            public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int
            {
                $this->recalled[] = [$id, $generation, $issuedFromState, $effectGroup];

                return 3;
            }

            public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array
            {
                $this->read[] = [$id, $generation, $issuedFromState, $effectGroup];

                return [false, true];
            }
        };
    }
}
