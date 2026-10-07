<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;
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

    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void
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
            'purpose' => $purpose->value,
            'claimedUntil' => null,
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
        $aliveSiblings = array_any($this->state->commands, static fn (array $sibling): bool => $sibling['id'] !== $row['id']
            && $sibling['correlationId'] === $row['correlationId']
            && $sibling['generation'] === $row['generation']
            && $sibling['issuedAtVersion'] === $row['issuedAtVersion']
            && in_array($sibling['status'], [OutboxStatus::Pending->value, OutboxStatus::Published->value], true));

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

    public function cancelPending(WorkflowId $id, int $generation, array $spared): int
    {
        $now = $this->clock->now();

        return $this->recall(static fn ($row): bool => $row['workflowType'] === $id->workflowType
            && $row['correlationId'] === $id->correlationId
            && $row['generation'] === $generation
            && $row['status'] === OutboxStatus::Pending->value
            && $row['purpose'] === CommandPurpose::Forward->value
            // a lease still running: the relay may be publishing the row right now
            && ! (is_string($row['claimedUntil']) && PointInTime::from($row['claimedUntil'])->isAfter($now))
            // an undone entry's do, still owed to the undo issued for it
            && ! array_any($spared, static fn (CompensationRecord $entry): bool => $entry->step === $row['issuedFromState'] && $entry->arm === $row['effectGroup']));
    }

    public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int
    {
        return $this->recall(static fn ($row): bool => $row['workflowType'] === $id->workflowType
            && $row['correlationId'] === $id->correlationId
            && $row['generation'] === $generation
            && $row['issuedFromState'] === $issuedFromState
            && $row['effectGroup'] === $effectGroup
            && $row['status'] === OutboxStatus::Pending->value
            && $row['purpose'] === CommandPurpose::Forward->value
            && $row['claimedUntil'] === null);
    }

    public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array
    {
        // the rows are kept in id order, and nothing can claim one between this read and the settle
        // of a single thread, so the order is the only half of the lock the model needs
        $forwards = array_filter($this->state->commands, static fn (array $row): bool => $row['workflowType'] === $id->workflowType
            && $row['correlationId'] === $id->correlationId
            && $row['generation'] === $generation
            && $row['issuedFromState'] === $issuedFromState
            && $row['effectGroup'] === $effectGroup
            && $row['purpose'] === CommandPurpose::Forward->value);

        return array_map(static fn (array $row): bool => $row['claimedUntil'] !== null, $forwards) |> array_values(...);
    }

    /**
     * The test's stand-in for a relay attempt that failed transiently: the pending row found by its
     * sealed message id spends an attempt and carries the claim marker, as the relay's back-off
     * leaves it. Returns false when no pending row carries the id.
     */
    public function markAttempted(string $messageId): bool
    {
        return $this->claim($messageId, $this->clock->now());
    }

    /**
     * The test's stand-in for the relay's live claim: the pending row found by its sealed message id
     * spends a claim and holds a lease until `$until`, as a drain leaves the row while it publishes.
     * Returns false when no pending row carries the id.
     */
    public function markClaimed(string $messageId, PointInTime $until): bool
    {
        return $this->claim($messageId, $until);
    }

    /**
     * The test's stand-in for the relay's mark: flip one pending row, found by its sealed message
     * id, to published. Returns false when no pending row carries the id, so a scenario cannot
     * silently publish a command that was never issued or was already settled.
     */
    public function markPublished(string $messageId): bool
    {
        $commandId = $this->pendingByMessageId($messageId);
        if ($commandId === null) {
            return false;
        }

        $row = $this->state->commands[$commandId];
        $row['status'] = OutboxStatus::Published->value;
        $row['processedAt'] = $this->clock->now()->toString();
        $row['claimedUntil'] = $row['processedAt'];
        $this->state->commands[$commandId] = $row;

        return true;
    }

    /**
     * @return array{id: int, workflowType: string, correlationId: string, bus: string, header: array<string, mixed>, content: array<string, mixed>, status: string, attempts: int, issuedFromState: string, issuedAtVersion: int, generation: int, effectGroup: string|null, purpose: string, claimedUntil: string|null, evidence: string, lastError: string|null, createdAt: string, processedAt: string|null}|null
     */
    private function rowByMessageId(string $correlationId, string $messageId): ?array
    {
        return array_find(
            $this->state->commands,
            static fn (array $row): bool => $row['correlationId'] === $correlationId && ($row['header'][Header::MessageId->value] ?? null) === $messageId,
        );
    }

    /**
     * The key of the pending row carrying `$messageId`, the lookup every relay stand-in shares.
     */
    private function pendingByMessageId(string $messageId): ?int
    {
        return array_find_key(
            $this->state->commands,
            static fn (array $row): bool => ($row['header'][Header::MessageId->value] ?? null) === $messageId && $row['status'] === OutboxStatus::Pending->value,
        );
    }

    /**
     * Spend one claim on the pending row carrying `$messageId` and set its claim marker to `$until`,
     * the one write both relay stand-ins share.
     */
    private function claim(string $messageId, PointInTime $until): bool
    {
        $commandId = $this->pendingByMessageId($messageId);
        if ($commandId === null) {
            return false;
        }

        $row = $this->state->commands[$commandId];
        // @infection-ignore-all; equivalent: the model has no relay, so the spent claim has no reader
        $row['attempts']++;
        $row['claimedUntil'] = $until->toString();
        $this->state->commands[$commandId] = $row;

        return true;
    }

    /**
     * Cancel every row `$selects` picks, the one write both recalls share.
     *
     * @param  callable(array<string, mixed>): bool  $selects
     * @return int the number of rows recalled
     */
    private function recall(callable $selects): int
    {
        $recalled = 0;
        foreach ($this->state->commands as $commandId => $row) {
            if (! $selects($row)) {
                continue;
            }
            $row['status'] = OutboxStatus::Cancelled->value;
            $row['processedAt'] = $this->clock->now()->toString();
            $this->state->commands[$commandId] = $row;
            $recalled++;
        }

        return $recalled;
    }

    private function instanceOf(string $workflowType, string $correlationId): ?WorkflowInstanceRow
    {
        return $this->state->instances[$workflowType."\x00".$correlationId]['row'] ?? null;
    }
}
