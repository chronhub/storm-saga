<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStateVersionMismatch;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowPauses;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * The step's load phase: find the row, judge a timer-borne signal's freshness, read the operator
 * freeze, resolve the pinned definition, and prove the row runnable under it, migrating forward
 * when it lawfully can. Pure preparation, no writes: what a migration produces here is persisted
 * by whatever the step writes, and the executor composes these reads per request kind.
 */
final readonly class StepLoader
{
    public function __construct(
        private WorkflowInstances $instances,
        private WorkflowPauses $pauses,
        private WorkflowTimers $timers,
    ) {}

    /**
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function find(WorkflowId $id): ?WorkflowInstanceRow
    {
        return $this->instances->find($id);
    }

    /**
     * Whether the instance or its whole type carries the operator freeze; how a frozen step is
     * refused stays the executor's per-kind judgment.
     *
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function frozen(?WorkflowInstanceRow $row, string $workflowType): bool
    {
        return ($row !== null && $row->pausedAt !== null) || $this->pauses->pausedType($workflowType);
    }

    /**
     * Resolve the definition the step drives by. When there is no row yet, a birth, take the latest
     * registered version and let the fresh row pin it via {@see WorkflowInstanceRow::fresh()}. For an
     * existing instance take the row's own pinned `definitionVersion`, so an evolved definition
     * coexists with the in-flight instances of the old one; this is version pinning.
     *
     * @throws WorkflowNotFound when no version of the type is registered
     * @throws WorkflowVersionNotFound when the pinned version was purged while this instance still runs
     */
    public function resolve(WorkflowRegistry $registry, string $workflowType, ?WorkflowInstanceRow $row): WorkflowDefinition
    {
        return $row === null
            ? $registry->get($workflowType)
            : $registry->get($workflowType, $row->definitionVersion);
    }

    /**
     * Prove the loaded row is RUNNABLE under the declaration, migrating it forward when it lawfully
     * can: a row AHEAD of the code is refused, since newer code wrote it and the fix is to deploy
     * forward; a row BEHIND is carried hop by hop through the workflow's `migrateState()` chain, in
     * memory here, persisted by whatever the step writes; without a migrator the older shape is
     * refused rather than silently fed to activities. A hop that throws breaks the chain loudly and
     * nothing partial survives: running under the fence inside the step's transaction, a later
     * refusal rolls it all back, and the pure chain simply re-runs on the next wake, which is what
     * makes it idempotent.
     *
     * @throws WorkflowStateVersionMismatch ahead of the code, behind with no migrator, or a broken hop
     */
    public function migrated(WorkflowDefinition $def, ?WorkflowInstanceRow $row): ?WorkflowInstanceRow
    {
        if ($row === null || $row->stateVersion === $def->stateVersion) {
            return $row;
        }

        if ($row->stateVersion > $def->stateVersion) {
            throw WorkflowStateVersionMismatch::aheadOfCode($row->workflowType, $row->correlationId, $row->stateVersion, $def->stateVersion);
        }
        if ($def->stateMigrator === null) {
            throw WorkflowStateVersionMismatch::behindWithoutMigrator($row->workflowType, $row->correlationId, $row->stateVersion, $def->stateVersion);
        }

        $vars = $row->vars;
        for ($from = $row->stateVersion; $from < $def->stateVersion; $from++) {
            try {
                $vars = ($def->stateMigrator)($from, $vars);
            } catch (Throwable $cause) {
                throw WorkflowStateVersionMismatch::chainBroke($row->workflowType, $row->correlationId, $from, $def->stateVersion, $cause);
            }
        }

        return $row->migratedTo($def->stateVersion, $vars);
    }

    /**
     * The freshness guard of a timer-borne signal, read under the fence: a runner claims a due row,
     * then drives its in-memory copy. A step committing in between, such as a wake that pushed the
     * deadline or an escalation that re-armed, can move the live row to the future, and the copy
     * becomes a straggler that would fire a deadline early. Sibling of the stale-state guard, which
     * catches a timer whose saga moved on; this one catches a timer whose saga stayed put but
     * re-armed.
     *
     * Per kind, the state-pinned kinds timeout, kick, and schedule are stale when the live row is
     * gone, consumed or canceled so the copy is a phantom, or moved to the future. The global
     * deadline is stale only when a live future row exists; an absent row still drives, because the
     * fired global is trusted even when its row was lost, the policy's documented promise.
     *
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     */
    public function timerSignalIsStale(WorkflowId $id, Signal $signal, PointInTime $now): bool
    {
        [$stateKey, $kind] = match ($signal->kind) {
            SignalKind::StateTimeout => [$signal->expectedStateKey, TimerKind::Timeout],
            SignalKind::Kick => [$signal->expectedStateKey, TimerKind::Kick],
            SignalKind::Schedule => [$signal->expectedStateKey, TimerKind::Schedule],
            SignalKind::GlobalDeadline => [WorkflowTimers::GLOBAL_KEY, TimerKind::Global],
            default => [null, null],
        };

        if ($kind === null || $stateKey === null) {
            return false; // not a timer signal, or no stale guard requested; nothing to check
        }

        $liveFireAt = $this->timers->fireAt($id, $stateKey, $kind);
        if ($liveFireAt === null) {
            return $kind !== TimerKind::Global; // pinned kinds: a phantom copy; global: the fired timer is trusted
        }

        return $liveFireAt->isAfter($now); // re-armed past the claim, so the copy is a straggler
    }
}
