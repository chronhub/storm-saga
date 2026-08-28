<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

/**
 * Raw PostgreSQL DDL for `workflow_instances`, the saga's identity: one row per LIVING saga instance
 * keyed by `(workflow_type, correlation_id)`. The durable half of that identity, the
 * `workflow_correlations` registry, lives in {@see WorkflowCorrelationsSchema}: one invariant across
 * two tables, and `storm:saga:install` writes both in the same transaction.
 *
 * The state the engine carries per instance:
 *
 * - `vars`, the JSON state bag; `retries`, the per-state attempt counters; `compensations`, the
 *   recorded rollback log or undo plan; `context`, the enriched audit context of actor and causation.
 *
 * - `retry_total`, the lifetime retry counter the workflow `retryBudget` caps.
 *
 * - `retimes`, the CURRENT visit's applied signal retimes, capped by `#[Retimable(maxRetimes:)]`.
 *   Durable like a lifetime counter, yet reset by any mover that rests on a new state, which is the
 *   per-visit philosophy of the retry attempt cap.
 *
 * - `arms`, the per-join arrival ledger keyed by the ISSUING state: which `#[JoinArm]` completions
 *   already landed while the saga rests on the joining wait. Engine-owned, so a join's progress never
 *   leaks into the app's `vars`. Monotonic per state and dead with the row, the compensatable-cycle
 *   build rule forbidding any revisit of a join state.
 *
 * - `families`, the per-family EXPECTED member count of the indexed `#[Spawns]` fan-outs, keyed by
 *   family. Stamped by the engine in the same write as the step that issued the spawns, it is the
 *   intention the family completeness gate counts spawned and living members against. Monotonic per
 *   family and dead with the row.
 *
 * - `parked`, the crossing an indexed family's gate rested and owes back, NULL on every row that
 *   owes none, which is every row outside that one wait. It holds the absorbed conclusion's CLASS,
 *   the wait it was absorbed at, and its causation, never a payload: the crossing routes and
 *   confirms from the class alone, the match having been proved and the extract applied when the
 *   conclusion landed. Nullable rather than defaulted, so "owes nothing" is one value and not two,
 *   and unindexed, since it is only ever read through the row's own primary key.
 *
 * - `waived_at`, with its partial index, the durable trace of a waived global cap, NULL until a saga
 *   waives its cap. There is no `locked_until`: fencing is a PG advisory lock, not a soft row lock.
 *
 * THREE VERSION AXES share the row, and confusing them is the standing hazard:
 *
 * - `version` is the OCC counter, bumped on each persisted step, the backstop under the advisory-lock
 *   fence.
 *
 * - `definition_version` is the pinned workflow version `#[Workflow(version:)]`, set at birth from the
 *   latest registered version and NEVER bumped. The engine resolves an instance's definition by it, so
 *   an evolved definition coexists with the in-flight instances of the old one.
 *
 * - `state_version` is the shape of the data bags `#[Workflow(stateVersion:)]`, stamped at birth.
 *   Unlike `definition_version` it is the one axis a living row is meant to be migrated across, by the
 *   explicit state migrator only; perpetual sagas never drain, so their data contract must be able to
 *   move while the graph stays pinned.
 *
 * The correlation index is UNIQUE: one saga per correlation is an enforced invariant, not a documented
 * assumption, since the outcome router resolves an instance from the correlation alone, so a second
 * type on one correlation would silently starve. That index only ever knows the LIVING rows; the ABA
 * a pruned-then-reborn correlation would open, and the registry that closes it at birth, are the
 * sibling schema's story.
 *
 * Parenthood is PROJECTED, never written: a child saga declares its parent in the birth context under
 * `$parent`, and the three generated columns materialize that declaration for the cascade and sweep
 * scans. The context is untouchable by any mover, so the columns cannot drift from the declaration
 * that bore them; a root instance projects NULL on all three. The unique correlation index doubles as
 * the spawn-idempotence guard, because a child correlation is minted deterministically from the
 * parent and slot.
 */
final class WorkflowInstancesSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS workflow_instances (
                    workflow_type  text   NOT NULL,
                    correlation_id text   COLLATE "C" NOT NULL,
                    state_key      text   NOT NULL,
                    status         text   NOT NULL DEFAULT 'running',
                    vars           jsonb  NOT NULL DEFAULT '{}'::jsonb,
                    retries        jsonb  NOT NULL DEFAULT '{}'::jsonb,
                    compensations  jsonb  NOT NULL DEFAULT '[]'::jsonb,
                    context        jsonb  NOT NULL DEFAULT '{}'::jsonb,
                    version        integer NOT NULL DEFAULT 0,
                    generation     integer NOT NULL DEFAULT 1,
                    definition_version integer NOT NULL DEFAULT 1,
                    state_version  integer NOT NULL DEFAULT 1,
                    retry_total    integer NOT NULL DEFAULT 0,
                    retimes        integer NOT NULL DEFAULT 0,
                    arms           jsonb  NOT NULL DEFAULT '{}'::jsonb,
                    families       jsonb  NOT NULL DEFAULT '{}'::jsonb,
                    parked         jsonb  NULL,
                    started_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    updated_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    waived_at      timestamptz(6) NULL,
                    paused_at      timestamptz(6) NULL,
                    paused_reason  text NULL,
                    parent_workflow_type  text GENERATED ALWAYS AS (context -> '$parent' ->> 'type') STORED,
                    parent_correlation_id text COLLATE "C" GENERATED ALWAYS AS (context -> '$parent' ->> 'correlation') STORED,
                    root_correlation_id   text COLLATE "C" GENERATED ALWAYS AS (context -> '$parent' ->> 'root') STORED,
                    CONSTRAINT workflow_instances_pk PRIMARY KEY (workflow_type, correlation_id),
                    CONSTRAINT workflow_instances_status_chk CHECK (status IN ('running', 'completed', 'halted', 'compensated')),
                    CONSTRAINT workflow_instances_version_chk CHECK (version >= 0),
                    CONSTRAINT workflow_instances_generation_chk CHECK (generation >= 1),
                    CONSTRAINT workflow_instances_definition_version_chk CHECK (definition_version >= 1),
                    CONSTRAINT workflow_instances_state_version_chk CHECK (state_version >= 1),
                    CONSTRAINT workflow_instances_retry_total_chk CHECK (retry_total >= 0),
                    CONSTRAINT workflow_instances_retimes_chk CHECK (retimes >= 0)
                )
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_instances_waived_idx ON workflow_instances (waived_at) WHERE waived_at IS NOT NULL
                SQL,
            // composite: the terminal prune filters on both columns, and every plain `WHERE status`
            // query, the maintenance reads included, uses the prefix, so a separate single-column
            // status index would be redundant
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_instances_status_updated_idx ON workflow_instances (status, updated_at)
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS workflow_instances_correlation_uq ON workflow_instances (correlation_id)
                SQL,
            // the cascade and the zombie sweep both scan "the children of parent X"; children per
            // parent are few, static slots, so the partial index carries no status predicate and
            // serves every liveness filter
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_instances_children_idx ON workflow_instances (parent_correlation_id) WHERE parent_correlation_id IS NOT NULL
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_instances_root_idx ON workflow_instances (root_correlation_id) WHERE root_correlation_id IS NOT NULL
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS workflow_instances',
        ];
    }
}
