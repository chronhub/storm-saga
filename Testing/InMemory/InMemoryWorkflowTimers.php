<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowTimerRow;
use Storm\Saga\Store\WorkflowTimerStore;

/**
 * In-memory `WorkflowTimerStore` over the shared state, the port's contract in sequential form: the
 * arm upsert resets a previous life's claim, budget and quarantine; the claim leases rather than
 * marks, honors the operator freeze, lets the global deadline through, and orders by fire instant
 * then id so a tie is deterministic where the DBAL adapter leaves it to the plan. The advisory
 * claim election and `SKIP LOCKED` have no sequential meaning and are deliberately absent; parallel
 * claimers remain the DBAL integration suite's.
 */
final readonly class InMemoryWorkflowTimers implements WorkflowTimerStore
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private InMemorySagaState $state,
        private Clock $clock,
    ) {}

    public function arm(WorkflowId $id, string $stateKey, TimerKind $kind, PointInTime $fireAt): void
    {
        foreach ($this->state->timers as $timerId => $timer) {
            if ($timer['workflowType'] === $id->workflowType && $timer['correlationId'] === $id->correlationId
                && $timer['stateKey'] === $stateKey && $timer['kind'] === $kind->value) {
                $reset = $timer;
                $reset['fireAt'] = $fireAt->toString();
                $reset['claimedAt'] = null;
                $reset['attempts'] = 0;
                $reset['parkedAt'] = null;
                $reset['lastError'] = null;
                $this->state->timers[$timerId] = $reset;

                return;
            }
        }

        $timerId = $this->state->nextTimerId++;
        $this->state->timers[$timerId] = [
            'id' => $timerId,
            'workflowType' => $id->workflowType,
            'correlationId' => $id->correlationId,
            'stateKey' => $stateKey,
            'kind' => $kind->value,
            'fireAt' => $fireAt->toString(),
            'claimedAt' => null,
            'attempts' => 0,
            'parkedAt' => null,
            'lastError' => null,
        ];
    }

    public function recordFailure(int $id, string $error): int
    {
        if (! isset($this->state->timers[$id])) {
            return 0;
        }

        $timer = $this->state->timers[$id];
        $attempts = $timer['attempts'] + 1;
        $timer['attempts'] = $attempts;
        $timer['lastError'] = $error;
        $this->state->timers[$id] = $timer;

        return $attempts;
    }

    public function park(int $id, string $error): void
    {
        $timer = $this->state->timers[$id] ?? null;
        if ($timer !== null) {
            $timer['parkedAt'] = $this->clock->now()->toString();
            $timer['lastError'] = $error;
            $this->state->timers[$id] = $timer;
        }
    }

    public function unpark(int $id): bool
    {
        $timer = $this->state->timers[$id] ?? null;
        if ($timer === null || $timer['parkedAt'] === null) {
            return false;
        }

        $timer['parkedAt'] = null;
        $timer['attempts'] = 0;
        $timer['lastError'] = null;
        $this->state->timers[$id] = $timer;

        return true;
    }

    public function fireAt(WorkflowId $id, string $stateKey, TimerKind $kind): ?PointInTime
    {
        foreach ($this->state->timers as $timer) {
            if ($timer['workflowType'] === $id->workflowType && $timer['correlationId'] === $id->correlationId
                && $timer['stateKey'] === $stateKey && $timer['kind'] === $kind->value) {
                return PointInTime::fromStorage($timer['fireAt']);
            }
        }

        return null;
    }

    public function claimDue(int $limit, PointInTime $now, int $leaseSeconds = 300): array
    {
        $nowStr = $now->toString();
        // @infection-ignore-all; equivalent: the floor guards a non-positive lease the port already refuses as positive-int, no admissible input reaches it
        $leaseFloor = max(1, $leaseSeconds);
        $leaseCutoff = $now->subSeconds($leaseFloor)->toString();

        $due = [];
        foreach ($this->state->timers as $timer) {
            if ($timer['fireAt'] > $nowStr || $timer['parkedAt'] !== null) {
                continue;
            }
            if ($timer['claimedAt'] !== null && $timer['claimedAt'] > $leaseCutoff) {
                continue;
            }
            // the operator freeze: state timers of a paused saga stay due at their original
            // instants; the global deadline claims through, the hard cap is not negotiable
            if ($timer['kind'] !== TimerKind::Global->value && $this->frozen($timer['workflowType'], $timer['correlationId'])) {
                continue;
            }
            $due[] = $timer;
        }

        usort($due, static fn (array $a, array $b): int => [$a['fireAt'], $a['id']] <=> [$b['fireAt'], $b['id']]);
        $claimed = array_slice($due, 0, $limit);

        foreach ($claimed as $timer) {
            $stamped = $timer;
            $stamped['claimedAt'] = $nowStr;
            $this->state->timers[$timer['id']] = $stamped;
        }

        return array_map(
            static fn (array $timer): WorkflowTimerRow => new WorkflowTimerRow(
                $timer['id'],
                $timer['workflowType'],
                $timer['correlationId'],
                $timer['stateKey'],
                TimerKind::from($timer['kind']),
                PointInTime::fromStorage($timer['fireAt']),
            ),
            $claimed,
        );
    }

    public function cancel(WorkflowId $id, string $stateKey): void
    {
        foreach ($this->state->timers as $timerId => $timer) {
            if ($timer['workflowType'] === $id->workflowType && $timer['correlationId'] === $id->correlationId && $timer['stateKey'] === $stateKey) {
                unset($this->state->timers[$timerId]);
            }
        }
    }

    public function listFor(WorkflowId $id): array
    {
        // @infection-ignore-all; equivalent: usort below reindexes, the values wrap only tidies the interim shape
        $matching = array_values(array_filter(
            $this->state->timers,
            static fn (array $timer): bool => $timer['workflowType'] === $id->workflowType && $timer['correlationId'] === $id->correlationId,
        ));
        usort($matching, static fn (array $a, array $b): int => [$a['fireAt'], $a['id']] <=> [$b['fireAt'], $b['id']]);

        return array_map(
            static fn (array $timer): WorkflowTimerRow => new WorkflowTimerRow(
                $timer['id'],
                $timer['workflowType'],
                $timer['correlationId'],
                $timer['stateKey'],
                TimerKind::from($timer['kind']),
                PointInTime::fromStorage($timer['fireAt']),
            ),
            $matching,
        );
    }

    private function frozen(string $workflowType, string $correlationId): bool
    {
        if (isset($this->state->typePauses[$workflowType])) {
            return true;
        }

        $entry = $this->state->instances[$workflowType."\x00".$correlationId] ?? null;

        return $entry !== null && $entry['row']->pausedAt instanceof PointInTime;
    }
}
