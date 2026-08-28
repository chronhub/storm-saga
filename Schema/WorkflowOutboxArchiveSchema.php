<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

/**
 * Raw PostgreSQL DDL for `workflow_outbox_archive`, the delivery audit of the saga's issued commands.
 *
 * The append-only sibling of `workflow_outbox` under `storm.saga.command_outbox.disposal: archive`.
 * What it holds matters more than for the event outbox: unlike events, a saga's commands exist
 * NOWHERE else once the hot row is gone. Zero updates, so neither churn nor bloat, and `archived_at`
 * is the publication instant.
 *
 * Two indexes for two jobs: a correlation index because looking a saga's command trail up IS this
 * table's purpose, and a BRIN on `archived_at` for the retention sweep.
 *
 * Failed rows never land here; dead letters stay in the hot table. Under the `delete` disposal the
 * table stays empty forever, which costs nothing.
 *
 * @see WorkflowOutboxSchema the hot table whose published rows land here
 */
final class WorkflowOutboxArchiveSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS workflow_outbox_archive (
                    id                bigint NOT NULL,
                    workflow_type     text NOT NULL,
                    correlation_id    text NOT NULL,
                    bus               text NOT NULL,
                    header            jsonb NOT NULL,
                    content           jsonb NOT NULL,
                    attempts          integer NOT NULL DEFAULT 0,
                    issued_from_state text NOT NULL DEFAULT '',
                    issued_at_version integer NOT NULL DEFAULT -1,
                    generation        integer NOT NULL DEFAULT 1,
                    evidence          text NOT NULL DEFAULT 'unknown',
                    created_at        timestamptz(6) NOT NULL,
                    archived_at       timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    CONSTRAINT workflow_outbox_archive_pk PRIMARY KEY (id),
                    CONSTRAINT workflow_outbox_archive_evidence_chk CHECK (evidence IN ('uncommitted', 'unknown')),
                    CONSTRAINT workflow_outbox_archive_attempts_chk CHECK (attempts >= 0),
                    CONSTRAINT workflow_outbox_archive_generation_chk CHECK (generation >= 1),
                    CONSTRAINT workflow_outbox_archive_issued_at_version_chk CHECK (issued_at_version >= -1)
                )
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_outbox_archive_corr_idx ON workflow_outbox_archive (correlation_id)
                SQL,
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS workflow_outbox_archive_age_idx ON workflow_outbox_archive USING brin (archived_at)
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
            'DROP TABLE IF EXISTS workflow_outbox_archive',
        ];
    }
}
