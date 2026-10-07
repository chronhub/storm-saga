<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Message\Message;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Workflow\CompensationRecord;

/**
 * A command store that keeps every write and every abort recall it receives, and recalls or claims
 * nothing: what a committer writes through the outbox outside the step's own entries.
 */
final class RecordingCommandStore implements WorkflowCommandStore
{
    /** @var list<array{id: WorkflowId, message: Message, from: string, version: int, generation: int, purpose: CommandPurpose, group: string|null}> */
    public array $written = [];

    /** @var list<array{id: WorkflowId, generation: int, spared: list<CompensationRecord>}> */
    public array $recalls = [];

    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void
    {
        $this->written[] = ['id' => $id, 'message' => $message, 'from' => $issuedFromState, 'version' => $issuedAtVersion, 'generation' => $generation, 'purpose' => $purpose, 'group' => $effectGroup];
    }

    public function cancelPending(WorkflowId $id, int $generation, array $spared): int
    {
        $this->recalls[] = ['id' => $id, 'generation' => $generation, 'spared' => $spared];

        return 0;
    }

    public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int
    {
        return 0;
    }

    public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array
    {
        return [];
    }
}
