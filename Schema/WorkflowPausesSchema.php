<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

/**
 * Raw PostgreSQL DDL for `workflow_pauses`, the TYPE-level pause registry: one row per workflow
 * type an operator froze, gating execution AND births, the stop-the-bleeding verb of an incident
 * window. Deliberately a table rather than config: the freeze is operational state that must
 * survive restarts, deploys and every worker's memory, and a row is what `storm:saga:resume` can
 * delete atomically.
 *
 * The registry is read once per step and once per start, by primary key: the price the verb's
 * completeness costs, accepted by design. Instance-level pause lives on the instance row itself,
 * in `workflow_instances.paused_at`, not here.
 *
 * @see WorkflowInstancesSchema
 */
final class WorkflowPausesSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS workflow_pauses (
                    workflow_type text NOT NULL,
                    paused_at     timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    reason        text NULL,
                    CONSTRAINT workflow_pauses_pk PRIMARY KEY (workflow_type)
                )
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
            'DROP TABLE IF EXISTS workflow_pauses',
        ];
    }
}
