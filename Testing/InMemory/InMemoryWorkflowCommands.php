<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Serializer\MessageSerializer;

/**
 * In-memory `WorkflowOutboxWriter` over the shared state: every sealed command is stored through
 * the REAL message serializer, so a captured command is detached the way a database row is, and a
 * test mutating an already-issued command object cannot reach what the runtime stored. Statuses,
 * provenance, the dead-letter flip, the redrive guards and their named refusals follow the port
 * contract in sequential form; `Raced` cannot be answered here, since nothing can move a row
 * between the decision and the write of a single thread.
 *
 * There is no relay: a pending command stays captured until the test delivers it explicitly, and
 * `markPublished()` is the test's stand-in for the relay's mark, never called by the runtime
 * itself.
 */
final readonly class InMemoryWorkflowCommands implements WorkflowOutboxWriter
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private InMemorySagaState $state,
        private MessageSerializer $serializer,
        private Clock $clock,
        private string $bus = 'storm.command.bus',
    ) {}

    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, ?string $effectGroup = null): void
    {
        ['header' => $header, 'content' => $content] = $this->serializer->serialize($message);

        // @infection-ignore-all; equivalent: the minted id is internal to the model, nothing reads it back
        $commandId = $this->state->nextCommandId++;
        $this->state->commands[$commandId] = [
            'id' => $commandId,
            'workflowType' => $id->workflowType,
            'correlationId' => $id->correlationId,
            // the durable row's mirror fields: bus, the write-time budget, the write-time
            // evidence and the created stamp are kept for row parity with the DBAL sibling; no
            // port reader consumes them before a later verb rewrites them
            // @infection-ignore-all
            'bus' => $this->bus,
            'header' => $header,
            'content' => $content,
            'status' => OutboxStatus::Pending->value,
            // @infection-ignore-all
            'attempts' => 0,
            'issuedFromState' => $issuedFromState,
            'issuedAtVersion' => $issuedAtVersion,
            'generation' => $generation,
            'effectGroup' => $effectGroup,
            // @infection-ignore-all
            'evidence' => EffectEvidence::Unknown->value,
            'lastError' => null,
            // @infection-ignore-all
            'createdAt' => $this->clock->now()->toString(),
            'processedAt' => null,
        ];
    }

    public function provenance(string $correlationId, string $messageId, int $generation): ?EffectProvenance
    {
        $row = $this->rowByMessageId($correlationId, $messageId);
        if ($row === null || $row['generation'] !== $generation
            || $row['status'] !== OutboxStatus::Failed->value || $row['issuedFromState'] === '') {
            return null;
        }

        $aliveSiblings = false;
        foreach ($this->state->commands as $sibling) {
            if ($sibling['id'] !== $row['id']
                && $sibling['correlationId'] === $row['correlationId']
                && $sibling['generation'] === $row['generation']
                && $sibling['issuedAtVersion'] === $row['issuedAtVersion']
                && in_array($sibling['status'], [OutboxStatus::Pending->value, OutboxStatus::Published->value], true)) {
                $aliveSiblings = true;
                // @infection-ignore-all; equivalent, break to continue: the flag is written `true` at
                // this one site and never back, so the siblings behind it can only rewrite the value
                // it already holds
                break;
            }
        }

        return new EffectProvenance(
            $row['issuedFromState'],
            $aliveSiblings,
            EffectEvidence::tryFrom($row['evidence']) ?? EffectEvidence::Unknown,
        );
    }

    public function markFailed(string $correlationId, string $messageId, string $error, EffectEvidence $evidence = EffectEvidence::Unknown): bool
    {
        $row = $this->rowByMessageId($correlationId, $messageId);
        if ($row === null || $row['status'] !== OutboxStatus::Published->value) {
            return false;
        }

        $row['status'] = OutboxStatus::Failed->value;
        $row['lastError'] = $error;
        $row['evidence'] = $evidence->value;
        $row['processedAt'] = $this->clock->now()->toString();
        $this->state->commands[$row['id']] = $row;

        return true;
    }

    public function redrive(string $correlationId, string $messageId, bool $force = false): RedriveOutcome
    {
        $row = $this->rowByMessageId($correlationId, $messageId);
        if ($row === null) {
            return RedriveOutcome::NotFound;
        }

        $saga = $this->instanceOf($row['workflowType'], $row['correlationId']);

        if ($row['status'] === OutboxStatus::Failed->value
            && ($row['evidence'] === EffectEvidence::Uncommitted->value || $force)
            && $saga?->status === WorkflowStatus::Running
            && $saga->generation === $row['generation']) {
            $row['status'] = OutboxStatus::Pending->value;
            // @infection-ignore-all; equivalent: the model has no relay, so the fresh budget has no reader
            $row['attempts'] = 0;
            $row['lastError'] = null;
            $row['processedAt'] = null;
            $row['evidence'] = EffectEvidence::Unknown->value;
            $this->state->commands[$row['id']] = $row;

            return RedriveOutcome::Redriven;
        }

        // the diagnosis names the refusing guard in the operator-actionable order the DBAL adapter
        // pins: the row's own state first, then the saga's, then the one refusal a force can lift
        if ($row['status'] !== OutboxStatus::Failed->value) {
            return RedriveOutcome::NotDeadLettered;
        }
        if ($saga?->status !== WorkflowStatus::Running) {
            return RedriveOutcome::SagaNotRunning;
        }
        if ($saga->generation !== $row['generation']) {
            return RedriveOutcome::StaleGeneration;
        }

        return RedriveOutcome::EffectUnproven;
    }

    public function cancelPending(WorkflowId $id, ?string $effectGroup = null): int
    {
        $recalled = 0;
        foreach ($this->state->commands as $commandId => $row) {
            if ($row['workflowType'] !== $id->workflowType || $row['correlationId'] !== $id->correlationId
                || $row['status'] !== OutboxStatus::Pending->value) {
                continue;
            }
            if ($effectGroup !== null && $row['effectGroup'] !== $effectGroup) {
                continue;
            }
            $row['status'] = OutboxStatus::Cancelled->value;
            $row['processedAt'] = $this->clock->now()->toString();
            $this->state->commands[$commandId] = $row;
            $recalled++;
        }

        return $recalled;
    }

    /**
     * The test's stand-in for the relay's mark: flip one pending row, found by its sealed message
     * id, to published. Returns false when no pending row carries the id, so a scenario cannot
     * silently publish a command that was never issued or was already settled.
     */
    public function markPublished(string $messageId): bool
    {
        foreach ($this->state->commands as $commandId => $row) {
            if (($row['header'][Header::MessageId->value] ?? null) === $messageId && $row['status'] === OutboxStatus::Pending->value) {
                $row['status'] = OutboxStatus::Published->value;
                $row['processedAt'] = $this->clock->now()->toString();
                $this->state->commands[$commandId] = $row;

                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: int, workflowType: string, correlationId: string, bus: string, header: array<string, mixed>, content: array<string, mixed>, status: string, attempts: int, issuedFromState: string, issuedAtVersion: int, generation: int, effectGroup: string|null, evidence: string, lastError: string|null, createdAt: string, processedAt: string|null}|null
     */
    private function rowByMessageId(string $correlationId, string $messageId): ?array
    {
        return array_find(
            $this->state->commands,
            fn ($row) => $row['correlationId'] === $correlationId && ($row['header'][Header::MessageId->value] ?? null) === $messageId
        );

    }

    private function instanceOf(string $workflowType, string $correlationId): ?WorkflowInstanceRow
    {
        return $this->state->instances[$workflowType."\x00".$correlationId]['row'] ?? null;
    }
}
