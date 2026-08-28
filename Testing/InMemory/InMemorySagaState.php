<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;

/**
 * The one shared table space of the in-memory saga runtime: instances, the durable correlation
 * registry, type pauses, timers, sealed commands, breaker snapshots, and every deterministic
 * counter. All adapters of one runtime hold THIS object, never private arrays of their own, so the
 * unit of work's snapshot spans everything a step may write and a mid-step failure restores the
 * whole world or none of it.
 *
 * Values are value-like on purpose: immutable row objects, enums, scalars and arrays of them, so a
 * plain property copy is a full snapshot. The held fence keys and the in-step flag stay OUTSIDE the
 * snapshot: restoring them would resurrect a lock the failing step still holds and releases in its
 * own finally.
 */
final class InMemorySagaState
{
    /** @var array<string, array{row: WorkflowInstanceRow, updatedAt: string}> */
    public array $instances = [];

    /**
     * The correlation registry, retention-permanent like its durable sibling: a claim outlives the
     * instance the prune deletes.
     *
     * @var array<string, list<array{generation: int, workflowType: string, definitionVersion: int, reuse: string, claimedAt: string}>>
     */
    public array $correlations = [];

    /** @var array<string, array{reason: string|null, pausedAt: string}> */
    public array $typePauses = [];

    /** @var array<int, array{id: int, workflowType: string, correlationId: string, stateKey: string, kind: string, fireAt: string, claimedAt: string|null, attempts: int, parkedAt: string|null, lastError: string|null}> */
    public array $timers = [];

    public int $nextTimerId = 1;

    /** @var array<int, array{id: int, workflowType: string, correlationId: string, bus: string, header: array<string, mixed>, content: array<string, mixed>, status: string, attempts: int, issuedFromState: string, issuedAtVersion: int, generation: int, effectGroup: string|null, evidence: string, lastError: string|null, createdAt: string, processedAt: string|null}> */
    public array $commands = [];

    public int $nextCommandId = 1;

    /** @var array<string, array{state: string, failures: int, openedAt: string|null}> */
    public array $breakers = [];

    public int $nextMessageSeq = 1;

    /** @var array<string, true> the fence keys currently held; never snapshotted */
    public array $held = [];

    /** Whether a fenced step is running; nesting is refused loud rather than silently mis-scoped. */
    public bool $inStep = false;

    public function instanceKey(WorkflowId $id): string
    {
        return $id->workflowType."\x00".$id->correlationId;
    }

    /**
     * A full copy of the table space and its counters; PHP copies the array properties by value and
     * every nested value is immutable or scalar, so the copy shares no mutable structure.
     */
    public function snapshot(): self
    {
        return clone $this;
    }

    /**
     * Put the whole table space back as the snapshot recorded it; the held keys and the in-step
     * flag are deliberately not restored, the failing step's own finally releases them.
     */
    public function restore(self $snapshot): void
    {
        $this->instances = $snapshot->instances;
        $this->correlations = $snapshot->correlations;
        $this->typePauses = $snapshot->typePauses;
        $this->timers = $snapshot->timers;
        $this->nextTimerId = $snapshot->nextTimerId;
        $this->commands = $snapshot->commands;
        $this->nextCommandId = $snapshot->nextCommandId;
        $this->breakers = $snapshot->breakers;
        $this->nextMessageSeq = $snapshot->nextMessageSeq;
    }
}
