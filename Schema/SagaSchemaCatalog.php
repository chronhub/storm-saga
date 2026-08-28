<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

use Storm\Support\Dbal\SchemaCatalog;

/**
 * What `storm:saga:install` proves after its DDL: the saga tables as the runtime relies on them.
 * The module's own verification data, kept beside the `*Schema` classes it mirrors, because Saga is
 * opt-in and depends on no core package, only on the shared {@see \Storm\Support\Dbal\SchemaProbe} that reads it.
 *
 * `SagaSchemaCompletenessTest` pins these lists against the DDL, so a schema class gaining a table
 * or an index without this catalog noticing turns a build red.
 *
 * @see \Storm\Saga\Console\InstallSagaCommand the installer that runs the probe inside its transaction;
 *      named by FQCN, never imported: the core must not point at its adapters
 */
final class SagaSchemaCatalog
{
    /**
     * Every column each table must expose, pinned to the shape the probe composes from the live
     * catalogs, `format_type` plus its nullability. A homonymous table with the right names but a
     * narrower type, a flipped nullability or a different PK passes a name-only probe and fails
     * later, inside the engine's writes; a null value here would fall back to a presence-only
     * check.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array COLUMNS = [
        'workflow_instances' => [
            'workflow_type' => 'text not null',
            'correlation_id' => 'text not null collate C',
            'state_key' => 'text not null',
            'status' => 'text not null',
            'vars' => 'jsonb not null',
            'retries' => 'jsonb not null',
            'compensations' => 'jsonb not null',
            'context' => 'jsonb not null',
            'version' => 'integer not null',
            'generation' => 'integer not null',
            'definition_version' => 'integer not null',
            'state_version' => 'integer not null',
            'retry_total' => 'integer not null',
            'retimes' => 'integer not null',
            'arms' => 'jsonb not null',
            'families' => 'jsonb not null',
            'parked' => 'jsonb null',
            'started_at' => 'timestamp(6) with time zone not null',
            'updated_at' => 'timestamp(6) with time zone not null',
            'waived_at' => 'timestamp(6) with time zone null',
            'paused_at' => 'timestamp(6) with time zone null',
            'paused_reason' => 'text null',
            'parent_workflow_type' => 'text null',
            'parent_correlation_id' => 'text null collate C',
            'root_correlation_id' => 'text null collate C',
        ],
        'workflow_pauses' => [
            'workflow_type' => 'text not null',
            'paused_at' => 'timestamp(6) with time zone not null',
            'reason' => 'text null',
        ],
        'workflow_correlations' => [
            'correlation_id' => 'text not null collate C',
            'generation' => 'integer not null',
            'workflow_type' => 'text not null',
            'definition_version' => 'integer not null',
            'reuse' => 'text not null',
            'claimed_at' => 'timestamp(6) with time zone not null',
            'closed_at' => 'timestamp(6) with time zone null',
            'final_status' => 'text null',
        ],
        'workflow_timers' => [
            'id' => 'bigint not null',
            'workflow_type' => 'text not null',
            'correlation_id' => 'text not null',
            'state_key' => 'text not null',
            'kind' => 'text not null',
            'fire_at' => 'timestamp(6) with time zone not null',
            'claimed_at' => 'timestamp(6) with time zone null',
            'attempts' => 'integer not null',
            'parked_at' => 'timestamp(6) with time zone null',
            'last_error' => 'text null',
            'created_at' => 'timestamp(6) with time zone not null',
        ],
        'workflow_outbox' => [
            'id' => 'bigint not null',
            'workflow_type' => 'text not null',
            'correlation_id' => 'text not null',
            'bus' => 'text not null',
            'header' => 'jsonb not null',
            'content' => 'jsonb not null',
            'status' => 'text not null',
            'attempts' => 'integer not null',
            'next_attempt_at' => 'timestamp(6) with time zone null',
            'last_error' => 'text null',
            'issued_from_state' => 'text not null',
            'issued_at_version' => 'integer not null',
            'generation' => 'integer not null',
            'evidence' => 'text not null',
            'effect_group' => 'text null',
            'created_at' => 'timestamp(6) with time zone not null',
            'processed_at' => 'timestamp(6) with time zone null',
        ],
        'workflow_outbox_archive' => [
            'id' => 'bigint not null',
            'workflow_type' => 'text not null',
            'correlation_id' => 'text not null',
            'bus' => 'text not null',
            'header' => 'jsonb not null',
            'content' => 'jsonb not null',
            'attempts' => 'integer not null',
            'issued_from_state' => 'text not null',
            'issued_at_version' => 'integer not null',
            'generation' => 'integer not null',
            'evidence' => 'text not null',
            'created_at' => 'timestamp(6) with time zone not null',
            'archived_at' => 'timestamp(6) with time zone not null',
        ],
        'circuit_breaker' => [
            'key' => 'text not null',
            'state' => 'text not null',
            'failures' => 'integer not null',
            'opened_at' => 'timestamp(6) with time zone null',
        ],
    ];

    /**
     * Named constraints per table; a non-null value is a fragment the live `pg_get_constraintdef`
     * must contain, a name alone proving nothing about a pre-existing homonym. Every primary key
     * pins its full column list, `PRIMARY KEY (…)` deparsing verbatim: `workflow_correlations_pk`
     * is the numbering backstop when two births race on the same correlation, and a homonym keyed
     * differently misroutes every upsert in silence. `workflow_timers_unique`, what makes re-arming
     * a timer an idempotent upsert rather than a duplicate row, stays presence-only.
     * `circuit_breaker` declares its `PRIMARY KEY` inline with no name of ours, so only its checks
     * are verified here; its column shapes are still pinned above.
     *
     * The `_chk` fragments are cut to what SURVIVES deparsing, which is rarely what the DDL reads:
     *
     * - A vocabulary written `IN (…)` comes back as `= ANY (ARRAY['x'::text, …])`, so the fragment
     *   is one quoted value, the one a narrowed homonym would drop first
     * - A negative floor comes back as `>= '-1'::integer`, so `issued_at_version` pins its column
     *   and its operator, never the bound
     * - A non-negative floor deparses verbatim and is pinned whole
     *
     * @var array<string, array<string, string|null>>
     */
    public const array CONSTRAINTS = [
        'workflow_instances' => [
            'workflow_instances_pk' => 'PRIMARY KEY (workflow_type, correlation_id)',
            'workflow_instances_status_chk' => "'running'",
            'workflow_instances_version_chk' => 'version >= 0',
            'workflow_instances_generation_chk' => 'generation >= 1',
            'workflow_instances_definition_version_chk' => 'definition_version >= 1',
            'workflow_instances_state_version_chk' => 'state_version >= 1',
            'workflow_instances_retry_total_chk' => 'retry_total >= 0',
            'workflow_instances_retimes_chk' => 'retimes >= 0',
        ],
        'workflow_pauses' => ['workflow_pauses_pk' => 'PRIMARY KEY (workflow_type)'],
        'workflow_correlations' => [
            'workflow_correlations_pk' => 'PRIMARY KEY (correlation_id, generation)',
            'workflow_correlations_reuse_chk' => "'reject'",
            'workflow_correlations_generation_chk' => 'generation >= 1',
            'workflow_correlations_definition_version_chk' => 'definition_version >= 1',
            'workflow_correlations_final_status_chk' => "'compensated'",
            'workflow_correlations_closure_chk' => '(closed_at IS NULL) = (final_status IS NULL',
        ],
        'workflow_timers' => [
            'workflow_timers_pk' => 'PRIMARY KEY (id)',
            'workflow_timers_unique' => null,
            'workflow_timers_kind_chk' => "'timeout'",
            'workflow_timers_attempts_chk' => 'attempts >= 0',
        ],
        'workflow_outbox' => [
            'workflow_outbox_pk' => 'PRIMARY KEY (id)',
            'workflow_outbox_status_chk' => "'pending'",
            'workflow_outbox_evidence_chk' => "'uncommitted'",
            'workflow_outbox_attempts_chk' => 'attempts >= 0',
            'workflow_outbox_generation_chk' => 'generation >= 1',
            'workflow_outbox_issued_at_version_chk' => 'issued_at_version >=',
        ],
        'workflow_outbox_archive' => [
            'workflow_outbox_archive_pk' => 'PRIMARY KEY (id)',
            'workflow_outbox_archive_evidence_chk' => "'uncommitted'",
            'workflow_outbox_archive_attempts_chk' => 'attempts >= 0',
            'workflow_outbox_archive_generation_chk' => 'generation >= 1',
            'workflow_outbox_archive_issued_at_version_chk' => 'issued_at_version >=',
        ],
        'circuit_breaker' => [
            'circuit_breaker_state_chk' => "'open'",
            'circuit_breaker_failures_chk' => 'failures >= 0',
        ],
    ];

    /**
     * Indexes per table; a non-null value is a fragment the live `indexdef` must contain, which is how
     * the shape that makes an index load-bearing gets verified instead of assumed.
     *
     * Two of them are invariants wearing an index's clothes, and a homonym that lost its predicate
     * would disable them in SILENCE, exactly the failure this whole probe exists to catch:
     *
     * - `workflow_correlations_spent_uq` IS the reuse refusal. Partial on `reuse = 'reject'`; without
     *   that predicate the index would also cover the `allow` rows, and a correlation legitimately
     *   running again would be refused instead. Lose the index entirely and a spent correlation runs
     *   a second time, which is the ABA the registry was built to close.
     * - `workflow_instances_correlation_uq` IS one-saga-per-correlation, the invariant the outcome
     *   router resolves against with `LIMIT 1`.
     *
     * `workflow_timers_due_idx` carries no fragment on purpose: what matters there is that it is FULL
     * rather than partial, and no substring can assert the ABSENCE of a predicate.
     */
    public const array INDEXES = [
        'workflow_instances' => [
            'workflow_instances_correlation_uq' => 'UNIQUE INDEX',
            'workflow_instances_status_updated_idx' => '(status, updated_at)',
            'workflow_instances_waived_idx' => 'WHERE (waived_at IS NOT NULL)',
            'workflow_instances_children_idx' => 'WHERE (parent_correlation_id IS NOT NULL)',
            'workflow_instances_root_idx' => 'WHERE (root_correlation_id IS NOT NULL)',
        ],
        'workflow_correlations' => [
            'workflow_correlations_spent_uq' => "WHERE (reuse = 'reject'",
        ],
        'workflow_timers' => ['workflow_timers_due_idx' => null],
        'workflow_outbox' => [
            'workflow_outbox_pending_idx' => "WHERE (status = 'pending'",
            'workflow_outbox_failed_corr_idx' => "WHERE (status = 'failed'",
            // the dead-letter flip's path: losing this turns markFailed() into a sequential scan
            // per failed command, precisely during a mass dead-letter
            'workflow_outbox_published_corr_idx' => "WHERE (status = 'published'",
            // the targeted recall's key: losing its effect_group predicate would silently widen it
            // to every ungrouped pending row
            'workflow_outbox_group_pending_idx' => 'effect_group IS NOT NULL',
        ],
        'workflow_outbox_archive' => [
            'workflow_outbox_archive_corr_idx' => null,
            'workflow_outbox_archive_age_idx' => 'brin',
        ],
    ];

    /**
     * The verification data as the shared probe reads it. No saga table is partitioned.
     */
    public static function catalog(): SchemaCatalog
    {
        return new SchemaCatalog(self::COLUMNS, self::CONSTRAINTS, self::INDEXES);
    }
}
