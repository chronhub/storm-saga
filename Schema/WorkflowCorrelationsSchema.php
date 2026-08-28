<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

/**
 * Raw PostgreSQL DDL for `workflow_correlations`, the durable memory of every correlation ever
 * claimed. The unique correlation index on `workflow_instances` only ever knows the LIVING rows: the
 * terminal prune deletes an instance, and without a durable memory the very same correlation could
 * start a SECOND run under an identity nothing can tell apart from the first. An artifact of the dead
 * run, a broker redelivery of its outcome or a dead-lettered command whose provenance the settle
 * pairs on, then reaches the living one, which is an ABA, not a race: both resolve their target with
 * `findByCorrelation()`, which answers with whatever row is alive NOW. The registry closes it by
 * construction, since the second run is never born.
 *
 * The claim is written at BIRTH, not at prune: an INDEX is what refuses the reuse, so the refusal is a
 * property of the schema rather than a look-then-write with a window between. Consequence to know: this
 * table is retention-PERMANENT under the reject regime. It is the sole carrier of the invariant, so
 * pruning it would reopen the very window it closes. A row is deliberately thin, no jsonb and roughly
 * two orders of magnitude under an instance row, because it outlives everything else the saga wrote.
 * `closed_at` and `final_status` are stamped by the terminal prune, so a correlation that has ended but
 * still sits in the hot table simply reads NULL there, its outcome still in `workflow_instances`.
 *
 * It is a JOURNAL, one row per generation, not a flat tombstone: under `allow` a correlation runs again
 * and each run keeps its own claim, so "which run issued this, and how did it end" stays answerable
 * years later, the question an operator holding a dead command actually asks. `generation` rides onto
 * `workflow_instances` and `workflow_outbox`, which is what seals an artifact to the run that wrote it;
 * `workflow_timers` deliberately does NOT carry it, since a timer never outlives its run, being
 * canceled at finalize, and a column with no invariant behind it is weight, not safety.
 *
 * One half of one invariant: this registry is not installable without `workflow_instances`, and
 * `storm:saga:install` writes both in the same transaction.
 *
 * @see WorkflowInstancesSchema the living half of the identity this registry outlives
 */
final class WorkflowCorrelationsSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            // the durable half of the identity. No foreign key to `workflow_instances`, deliberately and
            // in the opposite direction of the intuition: the claim OUTLIVES the instance it was born
            // with, so a key pointing at the pruned row is exactly what must not exist. Every read is by
            // primary key, so it carries no other index.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS workflow_correlations (
                    correlation_id     text COLLATE "C" NOT NULL,
                    generation         integer NOT NULL DEFAULT 1,
                    workflow_type      text NOT NULL,
                    definition_version integer NOT NULL,
                    reuse              text NOT NULL DEFAULT 'reject',
                    claimed_at         timestamptz(6) NOT NULL DEFAULT clock_timestamp(),
                    closed_at          timestamptz(6),
                    final_status       text,
                    CONSTRAINT workflow_correlations_pk PRIMARY KEY (correlation_id, generation),
                    CONSTRAINT workflow_correlations_reuse_chk CHECK (reuse IN ('reject', 'allow')),
                    CONSTRAINT workflow_correlations_generation_chk CHECK (generation >= 1),
                    CONSTRAINT workflow_correlations_definition_version_chk CHECK (definition_version >= 1),
                    CONSTRAINT workflow_correlations_final_status_chk CHECK (final_status IS NULL OR final_status IN ('running', 'completed', 'halted', 'compensated')),
                    CONSTRAINT workflow_correlations_closure_chk CHECK ((closed_at IS NULL) = (final_status IS NULL))
                )
                SQL,
            // THE refusal, and it is the schema's, not a runtime check: under `reject` a correlation may
            // hold exactly one claim, ever. The index is partial ON THE POLICY ITSELF, so `allow` rows
            // fall outside it and generations accumulate freely. A workflow that changes its declared
            // policy between two runs is therefore obeyed at each birth rather than at the first: the
            // declaration in force is the one that decides, which is what a dev editing an attribute
            // means. The composite pk stays the numbering backstop under both policies.
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS workflow_correlations_spent_uq ON workflow_correlations (correlation_id) WHERE reuse = 'reject'
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
            'DROP TABLE IF EXISTS workflow_correlations',
        ];
    }
}
