<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use JsonException;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Message\Header;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\CorrelationAlreadyConsumed;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CorrelationReuse;

/**
 * In-memory `WorkflowInstanceStore` over the shared state: the same contract as the DBAL adapter in
 * sequential form. OCC still refuses a stale version, the correlation registry still outlives the
 * instance, the state cap still measures the same four encoded bags, and the resume verbs still
 * pair the lift with the claims' release in one atomic unit, which here is a plain sequence under
 * the single-threaded model. The lock-flavored reads collapse to their plain siblings on purpose:
 * `countChildrenSerialized` counts like `countChildren` and `loadAdoptableParent` reads like
 * `findByCorrelation`, since sequential execution IS the serialization those locks buy. What no
 * sequential model can prove, real lock ordering and multi-connection races, remains the DBAL
 * integration suite's.
 */
final readonly class InMemoryWorkflowInstances implements WorkflowInstanceStore
{
    /** The same ceiling as the DBAL adapter, on the same four encoded bags; pinned by conformance. */
    private const int MAX_STATE_BYTES = 8192;

    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private InMemorySagaState $state,
        private Clock $clock,
    ) {}

    public function find(WorkflowId $id): ?WorkflowInstanceRow
    {
        return $this->state->instances[$this->state->instanceKey($id)]['row'] ?? null;
    }

    public function findByCorrelation(string $correlationId): ?WorkflowInstanceRow
    {
        foreach ($this->state->instances as $entry) {
            if ($entry['row']->correlationId === $correlationId) {
                return $entry['row'];
            }
        }

        return null;
    }

    public function create(WorkflowInstanceRow $row, CorrelationReuse $reuse = CorrelationReuse::Reject): int
    {
        $key = $this->state->instanceKey($row->id());
        if (isset($this->state->instances[$key])) {
            // the DBAL PK's translation: only a create-vs-create race lands there, and the engine's
            // start policy reports AlreadyStarted before ever reaching this sequentially
            throw SagaStorageFailure::unavailable(new RuntimeException(sprintf('Duplicate instance "%s/%s".', $row->workflowType, $row->correlationId)));
        }

        $living = $this->findByCorrelation($row->correlationId);
        if ($living instanceof WorkflowInstanceRow) {
            throw CorrelationAlreadyOwned::by($row->workflowType, $row->correlationId);
        }

        $claims = $this->state->correlations[$row->correlationId] ?? [];
        if ($reuse === CorrelationReuse::Reject) {
            foreach ($claims as $claim) {
                if ($claim['reuse'] === CorrelationReuse::Reject->value) {
                    throw CorrelationAlreadyConsumed::by($row->workflowType, $row->correlationId);
                }
            }
        }

        self::guardStateSize($row);

        $generation = 1 + array_reduce($claims, static fn (int $max, array $claim): int => max($max, $claim['generation']), 0);
        $now = $this->clock->now()->toString();
        $startedAt = $row->startedAt ?? $this->clock->now();

        $stored = self::replica($row, version: $row->version, startedAt: $startedAt, definitionVersion: $row->definitionVersion, generation: $generation, pausedAt: $row->pausedAt, pausedReason: $row->pausedReason);
        $this->state->instances[$key] = ['row' => $stored, 'updatedAt' => $now];
        // the registry's audit columns mirror the durable sibling; only `generation` and `reuse`
        // have port readers, and a lost predecessor row cannot change the max the next claim reads
        // nor the reject scan under one policy per type
        // @infection-ignore-all
        $this->state->correlations[$row->correlationId] = [...$claims, [
            'generation' => $generation,
            'workflowType' => $row->workflowType,
            'definitionVersion' => $row->definitionVersion,
            'reuse' => $reuse->value,
            'claimedAt' => $startedAt->toString(),
        ]];

        return $generation;
    }

    public function update(WorkflowInstanceRow $row): void
    {
        $key = $this->state->instanceKey($row->id());
        $entry = $this->state->instances[$key] ?? null;
        if ($entry === null || $entry['row']->version !== $row->version) {
            throw StaleWorkflowInstance::forStep($row->workflowType, $row->correlationId, $row->version);
        }

        self::guardStateSize($row);

        // the DBAL UPDATE physically cannot move the anchors or the freeze pair; preserve them from
        // the stored row the same way its SET list omits their columns
        $stored = $entry['row'];
        $this->state->instances[$key] = [
            'row' => self::replica($row, version: $row->version + 1, startedAt: $stored->startedAt, definitionVersion: $stored->definitionVersion, generation: $stored->generation, pausedAt: $stored->pausedAt, pausedReason: $stored->pausedReason),
            'updatedAt' => $this->clock->now()->toString(),
        ];
    }

    public function delete(WorkflowId $id): void
    {
        unset($this->state->instances[$this->state->instanceKey($id)]);
    }

    // The page default carries two arithmetic mutants that no honest unit can reach: telling 1000
    // from 999 or 1001 needs a thousand stranded rows in a fixture, and a suite that built them would
    // be measuring its own setup. The bound that MATTERS, that the page is limit-first and ordered,
    // is held by its own law. Left visible rather than ignored, the sibling mutants of this signature
    // being killed.
    public function strandedByFailedEffect(int $limit = 1000): array
    {
        $pairs = [];
        foreach ($this->state->commands as $command) {
            if ($command['status'] !== OutboxStatus::Failed->value) {
                continue;
            }
            $instance = $this->findByCorrelation($command['correlationId']);
            if ($instance?->status !== WorkflowStatus::Running) {
                continue;
            }
            $messageId = $command['header'][Header::MessageId->value] ?? null;
            $pair = [$command['correlationId'], is_string($messageId) ? $messageId : null];
            if (! in_array($pair, $pairs, true)) {
                $pairs[] = $pair;
            }
        }

        // the port's deterministic page, identical on both adapters: ordered, then bounded
        sort($pairs);

        return array_slice($pairs, 0, $limit);
    }

    public function waivedAndQuiet(int $quietForSeconds, int $limit = 100): array
    {
        // a zero window subtracts nothing, `subSeconds` refusing zero by type: the cutoff is the
        // clock's own instant, and the strict compare below reads as the DBAL twin's `updated_at < now()`
        $now = $this->clock->now();
        $cutoff = ($quietForSeconds > 0 ? $now->subSeconds($quietForSeconds) : $now)->toString();
        // @infection-ignore-all; equivalent: usort below reindexes, the values wrap only tidies the interim shape
        $quiet = array_values(array_filter(
            $this->state->instances,
            static fn (array $entry): bool => $entry['row']->status === WorkflowStatus::Running
                && $entry['row']->waivedAt instanceof PointInTime
                && $entry['updatedAt'] < $cutoff,
        ));
        // @infection-ignore-all; equivalent: the filter above admits only rows whose waivedAt is an instant, the nullsafe form serves the analyser on the closure boundary
        usort($quiet, static fn (array $a, array $b): int => strcmp((string) $a['row']->waivedAt?->toString(), (string) $b['row']->waivedAt?->toString()));

        return array_map(static fn (array $entry): WorkflowInstanceRow => $entry['row'], array_slice($quiet, 0, $limit));
    }

    public function runningCountsByVersion(): array
    {
        $counts = [];
        foreach ($this->state->instances as $entry) {
            $row = $entry['row'];
            if ($row->status === WorkflowStatus::Running) {
                $counts[$row->workflowType][$row->definitionVersion] = ($counts[$row->workflowType][$row->definitionVersion] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function countChildren(string $parentCorrelationId): int
    {
        return count($this->childrenOf($parentCorrelationId));
    }

    public function livingChildren(string $parentCorrelationId): array
    {
        return array_values(array_filter(
            $this->childrenOf($parentCorrelationId),
            static fn (WorkflowInstanceRow $row): bool => $row->status === WorkflowStatus::Running,
        ));
    }

    public function pauseInstance(WorkflowId $id, ?string $reason): bool
    {
        $key = $this->state->instanceKey($id);
        $entry = $this->state->instances[$key] ?? null;
        if ($entry === null || $entry['row']->status !== WorkflowStatus::Running || $entry['row']->pausedAt instanceof PointInTime) {
            return false;
        }

        $row = $entry['row'];
        $this->state->instances[$key]['row'] = self::replica($row, version: $row->version, startedAt: $row->startedAt, definitionVersion: $row->definitionVersion, generation: $row->generation, pausedAt: $this->clock->now(), pausedReason: $reason);

        return true;
    }

    public function resumeInstance(WorkflowId $id): bool
    {
        $key = $this->state->instanceKey($id);
        $entry = $this->state->instances[$key] ?? null;
        if ($entry === null || ! $entry['row']->pausedAt instanceof PointInTime) {
            return false;
        }

        $row = $entry['row'];
        $this->state->instances[$key]['row'] = self::replica($row, version: $row->version, startedAt: $row->startedAt, definitionVersion: $row->definitionVersion, generation: $row->generation, pausedAt: null, pausedReason: null);
        $this->releaseClaims(static fn (array $timer): bool => $timer['workflowType'] === $id->workflowType && $timer['correlationId'] === $id->correlationId);

        return true;
    }

    public function pauseType(string $workflowType, ?string $reason): void
    {
        // idempotent like the registry INSERT: the first stamp and reason stand
        $this->state->typePauses[$workflowType] ??= ['reason' => $reason, 'pausedAt' => $this->clock->now()->toString()];
    }

    public function resumeType(string $workflowType): bool
    {
        if (! isset($this->state->typePauses[$workflowType])) {
            return false;
        }

        unset($this->state->typePauses[$workflowType]);
        $this->releaseClaims(static fn (array $timer): bool => $timer['workflowType'] === $workflowType);

        return true;
    }

    public function pausedType(string $workflowType): bool
    {
        return isset($this->state->typePauses[$workflowType]);
    }

    public function countChildrenSerialized(string $parentCorrelationId): int
    {
        // sequential execution is the serialization the advisory spawn-lane lock buys
        return $this->countChildren($parentCorrelationId);
    }

    /**
     * Membership of an indexed family, the PHP reading of the byte range the Dbal twin scans.
     *
     * The suffix after `family-` must be DIGITS, which is the whole rule: a static slot named
     * `leg-final` beside a family `leg` is a different slot, not a fifth leg, and the build gate
     * admits it precisely because it cannot collide with a member. Matching on the prefix alone
     * would count it, and a family gate reading one more member than the database sees would cross
     * in a test and rest forever in production.
     */
    private static function isMember(string $correlationId, string $parentCorrelationId, string $family): bool
    {
        $prefix = $parentCorrelationId.ChildCorrelation::DELIMITER.$family.'-';

        return str_starts_with($correlationId, $prefix)
            && preg_match('/^\d+$/', substr($correlationId, strlen($prefix))) === 1;
    }

    public function spawnedMembers(string $parentCorrelationId, string $family): int
    {
        $spawned = 0;
        foreach (array_keys($this->state->correlations) as $correlationId) {
            if (self::isMember((string) $correlationId, $parentCorrelationId, $family)) {
                $spawned++;
            }
        }

        return $spawned;
    }

    public function livingMembers(string $parentCorrelationId, string $family): int
    {
        $living = 0;
        foreach ($this->state->instances as $entry) {
            if ($entry['row']->status === WorkflowStatus::Running && self::isMember($entry['row']->correlationId, $parentCorrelationId, $family)) {
                $living++;
            }
        }

        return $living;
    }

    public function loadAdoptableParent(string $correlationId): ?WorkflowInstanceRow
    {
        // the shared row lock orders the birth against the settle; sequentially they cannot overlap
        return $this->findByCorrelation($correlationId);
    }

    /**
     * @return list<WorkflowInstanceRow>
     */
    private function childrenOf(string $parentCorrelationId): array
    {
        $children = [];
        foreach ($this->state->instances as $entry) {
            if ($entry['row']->parentRef()?->correlationId === $parentCorrelationId) {
                $children[] = $entry['row'];
            }
        }

        return $children;
    }

    /**
     * @param  callable(array{workflowType: string, correlationId: string, kind: string, claimedAt: string|null, parkedAt: string|null}): bool  $selects
     */
    private function releaseClaims(callable $selects): void
    {
        foreach ($this->state->timers as $id => $timer) {
            // the claimed guard is cost, not correctness: releasing an unclaimed row rewrites its null
            // @infection-ignore-all
            if ($timer['claimedAt'] !== null && $timer['parkedAt'] === null && $timer['kind'] !== 'global' && $selects($timer)) {
                $this->state->timers[$id]['claimedAt'] = null;
            }
        }
    }

    /**
     * A stored copy of `$row` with the store-owned fields set explicitly: the OCC version, the
     * birth anchors, and the freeze pair, the fields the DBAL adapter's SQL owns rather than the
     * row's movers. Everything else is carried from `$row` unchanged.
     */
    private static function replica(WorkflowInstanceRow $row, int $version, ?PointInTime $startedAt, int $definitionVersion, int $generation, ?PointInTime $pausedAt, ?string $pausedReason): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow(
            $row->workflowType,
            $row->correlationId,
            $row->stateKey,
            $row->status,
            $row->vars,
            $row->retries,
            $row->context,
            $version,
            $startedAt,
            $row->compensations,
            $definitionVersion,
            $row->retryTotal,
            $generation,
            $row->waivedAt,
            $row->stateVersion,
            $row->retimes,
            $row->arms,
            $row->families,
            $pausedAt,
            $pausedReason,
            $row->parked,
        );
    }

    /**
     * The same measurement as the DBAL adapter: the four bags encoded, judged on their sum.
     *
     * @throws SagaStateTooLarge when the encoded bags exceed the cap
     * @throws SagaStorageFailure when a bag refuses to encode, the storage translation of a wiring bug
     */
    private static function guardStateSize(WorkflowInstanceRow $row): void
    {
        try {
            $bags = [
                'vars' => json_encode($row->vars, JSON_THROW_ON_ERROR),
                'retries' => json_encode($row->retries, JSON_THROW_ON_ERROR),
                // @infection-ignore-all; equivalent: json_encode of a CompensationRecord emits the same shape as toArray(), and only the SIZE is read here
                'compensations' => json_encode(array_map(static fn (CompensationRecord $record): array => $record->toArray(), $row->compensations), JSON_THROW_ON_ERROR),
                'context' => json_encode($row->context, JSON_THROW_ON_ERROR),
            ];
        } catch (JsonException $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        $sizes = array_map(strlen(...), $bags);
        $total = array_sum($sizes);
        if ($total > self::MAX_STATE_BYTES) {
            throw SagaStateTooLarge::forInstance($row->workflowType, $row->correlationId, $total, self::MAX_STATE_BYTES, $sizes);
        }
    }
}
