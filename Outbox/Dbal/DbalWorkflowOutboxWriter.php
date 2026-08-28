<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Storm\Contracts\Message\SerializablePayload;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Serializer\MessageSerializer;
use Storm\Support\Dbal\BatchedDelete;
use Storm\Support\OutboxDisposal;

/**
 * Stores a saga's sealed outgoing message in `workflow_outbox` on the given connection, so the insert
 * commits inside the step's transaction, atomic with the state advance and never lost. The row holds the
 * serialized `(header, content)` pair as jsonb plus the target bus, keyed by the saga's workflow type
 * and correlation for the relay to drain. Serializes via `MessageSerializer`, so the wrapped command
 * must be a `SerializablePayload`.
 *
 * @see \Storm\Saga\Outbox\HopProtocol
 * @see \Storm\Contracts\Message\SerializablePayload
 */
final readonly class DbalWorkflowOutboxWriter implements WorkflowOutboxWriter
{
    /** The age gate shared by every retention predicate: a row older than the cut on `processed_at`. */
    private const string AGE_CLAUSE = "processed_at < now() - (CAST(:age AS bigint) * interval '1 second')";

    /**
     * The retention rule as a sprintf format; `prunable()` binds the statuses from {@see \Storm\Saga\Outbox\OutboxStatus}.
     * It matches a row the pipeline is DONE with, older than the age cut. `published` and `cancelled` are
     * audit-only the moment they are stamped, prunable purely by age. A `failed` row is prunable by age
     * ONLY once its saga is no longer running: while it runs, the row is the reconcile input for
     * `strandedByFailedEffect`, so pruning it would erase the durable backstop that re-derives a lost
     * settle. `pending` is never touched, still the relay's. Age gates on `processed_at`, stamped by
     * publish, dead-letter, and recall alike. The subquery qualifies by the real table name, since
     * BatchedDelete runs the predicate unaliased.
     */
    private const string PRUNABLE_FORMAT = <<<'SQL'
        processed_at < now() - (CAST(:age AS bigint) * interval '1 second')
        AND (
            status IN ('%s', '%s')
            OR (status = '%s' AND NOT EXISTS (
                SELECT 1 FROM workflow_instances i
                WHERE i.correlation_id = workflow_outbox.correlation_id AND i.status = '%s'
            ))
        )
        SQL;

    public function __construct(
        private Connection $connection,
        private MessageSerializer $serializer,
        /**
         * The bus name stamped on every row, the standalone default; the bundle overrides it with
         * its command-bus constant. The relay's publisher honors only that one bus today; the
         * column exists so a multi-bus relay could route without a schema change.
         */
        private string $bus = 'storm.command.bus',
        /**
         * What retention does with a `published` row once it ages out: `Delete` drops it, `Archive` moves
         * it to the cold `workflow_outbox_archive` sibling as the delivery audit. This is the ONE place
         * disposal happens; the relay only ever marks `published`, so the row survives long enough for
         * the settle to pair on it. Wired from `storm.saga.command_outbox.disposal`; the package default
         * is `Delete`, a queue table being no ledger.
         */
        private OutboxDisposal $disposal = OutboxDisposal::Delete,
    ) {}

    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, ?string $effectGroup = null): void
    {
        ['header' => $header, 'content' => $content] = $this->serializer->serialize($message);

        try {
            $this->connection->executeStatement(
                /** @lang PostgreSQL */
                'INSERT INTO workflow_outbox (workflow_type, correlation_id, bus, header, content, issued_from_state, issued_at_version, generation, effect_group)
                 VALUES (:type, :corr, :bus, CAST(:header AS jsonb), CAST(:content AS jsonb), :from_state, :at_version, :generation, :effect_group)',
                [
                    'type' => $id->workflowType,
                    'corr' => $id->correlationId,
                    'bus' => $this->bus,
                    'header' => json_encode($header, JSON_THROW_ON_ERROR),
                    'content' => json_encode($content, JSON_THROW_ON_ERROR),
                    'from_state' => $issuedFromState,
                    'at_version' => $issuedAtVersion,
                    'generation' => $generation,
                    'effect_group' => $effectGroup,
                ],
                ['at_version' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER],
            );
        } catch (Exception|JsonException $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    public function provenance(string $correlationId, string $messageId, int $generation): ?EffectProvenance
    {
        try {
            $row = $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                sprintf(
                    "SELECT o.issued_from_state, o.evidence,
                            EXISTS(
                                SELECT 1 FROM workflow_outbox s
                                WHERE s.correlation_id = o.correlation_id
                                  AND s.generation = o.generation
                                  AND s.issued_at_version = o.issued_at_version
                                  AND s.id <> o.id
                                  AND s.status IN ('%s', '%s')
                            ) AS alive_siblings
                     FROM workflow_outbox o
                     WHERE o.correlation_id = :corr AND o.generation = :generation
                       AND o.header->>'%s' = :mid AND o.status = '%s'",
                    OutboxStatus::Pending->value,
                    OutboxStatus::Published->value,
                    Header::MessageId->value,
                    OutboxStatus::Failed->value,
                ),
                ['corr' => $correlationId, 'mid' => $messageId, 'generation' => $generation],
                ['generation' => ParameterType::INTEGER],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        if ($row === false || $row['issued_from_state'] === '') {
            // unknown row, one that predates the provenance columns, a command issued by an EARLIER run
            // of a reusing correlation, what the generation filter exists for, or a row that has
            // LEFT the dead-letter state. Unpaired either way, and the policy treats
            // unpaired as escalate-only, never as a settle.
            return null;
        }

        return new EffectProvenance(
            (string) $row['issued_from_state'],
            (bool) $row['alive_siblings'],
            EffectEvidence::tryFrom((string) $row['evidence']) ?? EffectEvidence::Unknown,
        );
    }

    public function markFailed(string $correlationId, string $messageId, string $error, EffectEvidence $evidence = EffectEvidence::Unknown): bool
    {
        try {
            $flipped = $this->connection->executeStatement(
                /** @lang PostgreSQL */
                sprintf(
                    "UPDATE workflow_outbox
                     SET status = '%s', last_error = :error, evidence = :evidence, processed_at = clock_timestamp()
                     WHERE correlation_id = :corr AND header->>'%s' = :mid AND status = '%s'",
                    OutboxStatus::Failed->value,
                    Header::MessageId->value,
                    OutboxStatus::Published->value,
                ),
                ['error' => $error, 'corr' => $correlationId, 'mid' => $messageId, 'evidence' => $evidence->value],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return $flipped > 0;
    }

    public function redrive(string $correlationId, string $messageId, bool $force = false): RedriveOutcome
    {
        try {
            // every guard lives in the predicate: the join to the instance decides "still running" and
            // "current generation" in the same statement that flips the row, so nothing can settle,
            // prune or re-run the saga between the decision and the write
            $redriven = $this->connection->executeStatement(
                /** @lang PostgreSQL */
                sprintf(
                    "UPDATE workflow_outbox o
                     SET status = '%s', attempts = 0, last_error = NULL, processed_at = NULL, evidence = '%s'
                     FROM workflow_instances i
                     WHERE o.correlation_id = :corr AND o.header->>'%s' = :mid AND o.status = '%s'
                       AND (o.evidence = '%s' OR :force)
                       AND i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                       AND i.status = '%s' AND i.generation = o.generation",
                    OutboxStatus::Pending->value,
                    EffectEvidence::Unknown->value, // a row back in flight has proven nothing yet
                    Header::MessageId->value,
                    OutboxStatus::Failed->value,
                    EffectEvidence::Uncommitted->value,
                    WorkflowStatus::Running->value,
                ),
                ['corr' => $correlationId, 'mid' => $messageId, 'force' => $force],
                ['force' => ParameterType::BOOLEAN],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return $redriven > 0 ? RedriveOutcome::Redriven : $this->whyNotRedriven($correlationId, $messageId, $force);
    }

    /**
     * Which guard rejected it, asked ONLY after the update changed nothing. The decision was made
     * atomically above; this read merely names it, so a diagnosis that raced with a concurrent settle
     * can be slightly stale without ever having decided anything. An operator handed a bare "nothing
     * happened" goes and edits SQL by hand, which is the outcome this whole verb exists to prevent.
     *
     * @throws SagaStorageFailure when the storage fails
     */
    private function whyNotRedriven(string $correlationId, string $messageId, bool $force): RedriveOutcome
    {
        try {
            $row = $this->connection->fetchAssociative(
                /** @lang PostgreSQL */
                sprintf(
                    "SELECT o.status, o.evidence, o.generation, i.status AS saga_status, i.generation AS saga_generation
                     FROM workflow_outbox o
                     LEFT JOIN workflow_instances i
                       ON i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                     WHERE o.correlation_id = :corr AND o.header->>'%s' = :mid",
                    Header::MessageId->value,
                ),
                ['corr' => $correlationId, 'mid' => $messageId],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        if ($row === false) {
            return RedriveOutcome::NotFound;
        }

        // ordered by what an operator can act on: the row's own state first, then the saga's, then the
        // one refusal a --force can lift, which must be reported LAST so it is never the answer given
        // for a saga that is beyond repair anyway
        if ((string) $row['status'] !== OutboxStatus::Failed->value) {
            return RedriveOutcome::NotDeadLettered;
        }

        if ($row['saga_status'] === null || (string) $row['saga_status'] !== WorkflowStatus::Running->value) {
            return RedriveOutcome::SagaNotRunning;
        }

        if ((int) $row['saga_generation'] !== (int) $row['generation']) {
            return RedriveOutcome::StaleGeneration;
        }

        if ((string) $row['evidence'] !== EffectEvidence::Uncommitted->value) {
            return RedriveOutcome::EffectUnproven;
        }

        // every guard now reads as satisfied, yet the update changed nothing: the row moved between
        // the two statements. Saying NotFound here, of a row just read, would be the diagnosis
        // inventing a reason rather than reporting one.
        return RedriveOutcome::Raced;
    }

    public function cancelPending(WorkflowId $id, ?string $effectGroup = null): int
    {
        // Only `pending` rows move: a row the relay is claiming right now is row-locked
        // with FOR UPDATE SKIP LOCKED; this UPDATE blocks on it, then re-evaluates it as published
        // and leaves it alone. Keyed by (type, correlation): never another saga's rows. A non-null
        // `$effectGroup` narrows the recall to ONE concurrent arm's rows, the race's targeted half,
        // served by the group partial index, leaving sibling arms in flight.
        try {
            $sql = sprintf(
                "UPDATE workflow_outbox
                 SET status = '%s', processed_at = clock_timestamp()
                 WHERE workflow_type = :type AND correlation_id = :corr AND status = '%s'",
                OutboxStatus::Cancelled->value,
                OutboxStatus::Pending->value,
            );
            $params = ['type' => $id->workflowType, 'corr' => $id->correlationId];

            if ($effectGroup !== null) {
                $sql .= ' AND effect_group = :effect_group';
                $params['effect_group'] = $effectGroup;
            }

            return (int) $this->connection->executeStatement($sql, $params);
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * Count the rows the retention rule would prune at `$ageSeconds`; the dry-run preview of `prune()`.
     *
     * @throws SagaStorageFailure on a saga-store read failure
     */
    public function countPrunable(int $ageSeconds): int
    {
        try {
            return (int) $this->connection->fetchOne(
                /** @lang PostgreSQL */
                'SELECT count(*) FROM workflow_outbox WHERE '.self::prunable(),
                ['age' => $ageSeconds],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * Count the archived rows `pruneArchive()` would delete at `$ageSeconds`; the other half of the
     * dry-run preview.
     *
     * Its own counter because the archive is a second table and a second destructive arm: a preview
     * that summed only the live outbox reported "nothing changed" over a trail that exists nowhere
     * else. Under the `Delete` disposal the archive is empty and this answers zero, which is honest.
     *
     * @throws SagaStorageFailure on a saga-store read failure
     */
    public function countArchive(int $ageSeconds): int
    {
        try {
            return (int) $this->connection->fetchOne(
                /** @lang PostgreSQL */
                "SELECT count(*) FROM workflow_outbox_archive WHERE archived_at < now() - (CAST(:age AS bigint) * interval '1 second')",
                ['age' => $ageSeconds],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * Prune the retention-expired rows in `$batch`-capped statements to avoid a long lock: per
     * `PRUNABLE_FORMAT`, `published` and `cancelled` by age, `failed` by age once its saga is no longer
     * running. This cannot race the live relay, which works on `pending`.
     *
     * Disposal decides the `published` row's fate. Under `Delete` every prunable row is deleted, one
     * batched sweep. Under `Archive` the `published` rows, the issued-command trail that exists nowhere
     * else, are MOVED to the cold `workflow_outbox_archive` by age and later swept from there by
     * `pruneArchive()`; the `cancelled` and settled-`failed` rows, which carry no delivery audit,
     * are still deleted. Either way the count is the total rows this pass acted on.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws SagaStorageFailure on a saga-store delete failure
     */
    public function prune(int $ageSeconds, int $batch): int
    {
        try {
            if ($this->disposal === OutboxDisposal::Archive) {
                $moved = $this->archivePublished($ageSeconds, $batch);
                $deleted = BatchedDelete::run($this->connection, 'workflow_outbox', self::nonPublishedPrunable(), ['age' => $ageSeconds], $batch);

                return $moved + $deleted;
            }

            return BatchedDelete::run($this->connection, 'workflow_outbox', self::prunable(), ['age' => $ageSeconds], $batch);
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * Move the aged-out `published` rows to the cold archive in `$batch`-capped statements: each is one
     * atomic `DELETE … RETURNING` piped into the archive `INSERT`, so a row is never in both tables and
     * never in neither, looped until none remain. `FOR UPDATE SKIP LOCKED` keeps two concurrent cleanups
     * from contending; it never races the live relay, which works on `pending`. `archived_at` defaults to
     * the move instant, starting the cold table's own retention clock.
     *
     * @param  positive-int  $batch
     * @return int rows archived
     *
     * @throws Exception on a DBAL failure of the move statement
     */
    private function archivePublished(int $ageSeconds, int $batch): int
    {
        $sql = sprintf(
            /** @lang PostgreSQL */
            <<<'SQL'
                WITH due AS (
                    SELECT id FROM workflow_outbox WHERE %s ORDER BY id LIMIT %d FOR UPDATE SKIP LOCKED
                ), moved AS (
                    DELETE FROM workflow_outbox WHERE id IN (SELECT id FROM due)
                    RETURNING id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, created_at
                )
                INSERT INTO workflow_outbox_archive (id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, created_at)
                SELECT id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, created_at FROM moved
                SQL,
            self::publishedPrunable(),
            $batch,
        );

        $total = 0;
        do {
            $moved = (int) $this->connection->executeStatement($sql, ['age' => $ageSeconds]);
            $total += $moved;
        } while ($moved > 0);

        return $total;
    }

    /**
     * Prune the archive under `storm.saga.command_outbox.disposal: archive` by age; the cold table's
     * retention, entirely published-by-construction, served by the BRIN on `archived_at`. Under `delete`
     * disposal the archive is empty and this is a no-op. Cannot race the live relay: the relay only ever
     * INSERTs here as the atomic move, never reads.
     *
     * @param  positive-int  $batch
     * @return int rows pruned
     *
     * @throws SagaStorageFailure on a saga-store delete failure
     */
    public function pruneArchive(int $ageSeconds, int $batch): int
    {
        try {
            return BatchedDelete::run(
                $this->connection,
                'workflow_outbox_archive',
                "archived_at < now() - (CAST(:age AS bigint) * interval '1 second')",
                ['age' => $ageSeconds],
                $batch,
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /** The retention predicate with its statuses bound from the shared vocabulary. */
    private static function prunable(): string
    {
        return sprintf(
            self::PRUNABLE_FORMAT,
            OutboxStatus::Published->value,
            OutboxStatus::Cancelled->value,
            OutboxStatus::Failed->value,
            WorkflowStatus::Running->value,
        );
    }

    /**
     * The archive-mode split of `prunable()`: the aged-out `published` rows moved to the archive,
     * and the aged-out `cancelled` / settled-`failed` rows deleted with no delivery audit. Their union is
     * exactly `prunable()`, so `countPrunable()` still reports every row a prune will touch.
     */
    private static function publishedPrunable(): string
    {
        return sprintf(
            self::AGE_CLAUSE." AND status = '%s'",
            OutboxStatus::Published->value,
        );
    }

    private static function nonPublishedPrunable(): string
    {
        return sprintf(
            self::AGE_CLAUSE." AND (status = '%s' OR (status = '%s' AND NOT EXISTS (
                SELECT 1 FROM workflow_instances i
                WHERE i.correlation_id = workflow_outbox.correlation_id AND i.status = '%s'
            )))",
            OutboxStatus::Cancelled->value,
            OutboxStatus::Failed->value,
            WorkflowStatus::Running->value,
        );
    }
}
