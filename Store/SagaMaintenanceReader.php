<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The maintenance-read capability of the saga store: the durable inputs of the cleanup's reconcile
 * and sweep, and the version-pinning purge view. Cold reads for the operator surfaces; nothing here
 * runs on a step's path. Every method may throw the port-owned `SagaStorageFailure` with the
 * driver's failure wrapped.
 *
 * @see SagaStorageFailure
 */
interface SagaMaintenanceReader
{
    /**
     * The sagas stranded by a dead-lettered effect: still `running` while one of their issued commands
     * sits `failed` in the saga outbox, marked either by the relay's own dead-letter or by the failure
     * listener's consumer-side mark. The durable input of the cleanup's reconcile: the in-process
     * settle signals are non-durable and a crash in that window loses them, so this re-derives the
     * truth from storage. One pair per failed row; the sealed message id rides along so the replayed
     * settle stays PAIRED to the command that died, and is null for an unreadable header.
     *
     * A DETERMINISTIC page, ordered by correlation then message id and bounded by `$limit`: a mass
     * dead-letter is also a producer of stranded rows, and an unbounded, planner-ordered scan
     * would make one invocation attempt every settle at once while a persistent poison reshuffles
     * which sagas get reconciled run to run. Settled rows leave the result set, so repeated
     * invocations drain the backlog page by page.
     *
     * @param  positive-int  $limit
     * @return list<array{0: string, 1: string|null}> pairs of correlationId and sealed messageId
     */
    public function strandedByFailedEffect(int $limit = 1000): array;

    /**
     * The sagas left QUIET by a waived global cap: still `running`, `waived_at` stamped, untouched for
     * at least `$quietForSeconds`. The durable input of the cleanup's sweep: the waive's
     * `SagaAwaitOverdue` announcement is post-commit and non-durable, so a crash in that window loses it
     * and this re-derives the hand-off from storage. The quiet window keeps a saga actively advancing on
     * a late outcome out of the report.
     *
     * @return list<WorkflowInstanceRow>
     */
    public function waivedAndQuiet(int $quietForSeconds, int $limit = 100): array;

    /**
     * Running-instance counts per pinned version: `workflow_type => (definition_version => count)`. Only
     * `running` instances, since a settled saga never re-resolves its definition and so cannot be
     * stranded by a purge. The input to the version-pinning purge view; a registered version with zero
     * running instances has drained and its class can be removed.
     *
     * @return array<string, array<int, int>>
     */
    public function runningCountsByVersion(): array;
}
