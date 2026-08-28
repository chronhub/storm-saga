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
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\WorkflowId;

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

        new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->write($id, $command, 'credit', 3, 1);

        $this->assertCount(1, $writer->written);
        [$writtenId, $message, $fromState, $atVersion] = $writer->written[0];
        $this->assertSame($id, $writtenId);
        $this->assertSame($command, $message->message());
        $this->assertSame('credit', $fromState); // the provenance rides through untouched
        $this->assertSame(3, $atVersion);
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

        $recalled = new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $writer)->cancelPending($id);

        $this->assertSame(7, $recalled);
        $this->assertSame([$id], $writer->cancelled);
    }

    /**
     * @return WorkflowOutboxWriter&object{written: list<array{WorkflowId, Message, string, int, int}>, cancelled: list<WorkflowId>}
     */
    private function capturingWriter(): WorkflowOutboxWriter
    {
        return new class() implements WorkflowOutboxWriter
        {
            /** @var list<array{WorkflowId, Message, string, int, int}> */
            public array $written = [];

            /** @var list<WorkflowId> */
            public array $cancelled = [];

            public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation = 1, ?string $effectGroup = null): void
            {
                $this->written[] = [$id, $message, $issuedFromState, $issuedAtVersion, $generation];
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

            public function cancelPending(WorkflowId $id, ?string $effectGroup = null): int
            {
                $this->cancelled[] = $id;

                return 7;
            }
        };
    }
}
