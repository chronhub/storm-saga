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
use Storm\Saga\Outbox\CommandPurpose;
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

    public function write(WorkflowId $id, Message $message, string $issuedFromState, int $issuedAtVersion, int $generation, CommandPurpose $purpose, ?string $effectGroup = null): void
    {
        ['header' => $header, 'content' => $content] = $this->serializer->serialize($message);

        try {
            $this->connection->executeStatement(
                /* language=PostgreSQL */
                'INSERT INTO workflow_outbox (workflow_type, correlation_id, bus, header, content, issued_from_state, issued_at_version, generation, purpose, effect_group)
                 VALUES (:type, :corr, :bus, CAST(:header AS jsonb), CAST(:content AS jsonb), :from_state, :at_version, :generation, :purpose, :effect_group)',
                [
                    'type' => $id->workflowType,
                    'corr' => $id->correlationId,
                    'bus' => $this->bus,
                    'header' => json_encode($header, JSON_THROW_ON_ERROR),
                    'content' => json_encode($content, JSON_THROW_ON_ERROR),
                    'from_state' => $issuedFromState,
                    'at_version' => $issuedAtVersion,
                    'generation' => $generation,
                    'purpose' => $purpose->value,
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
                sprintf(
                    /* language=PostgreSQL */
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
                sprintf(
                    /* language=PostgreSQL */
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

    /**
     * {@inheritDoc}
     *
     * The flip runs under the saga's own step fence, a `PgAdvisoryFence` over this writer's connection,
     * so the lock, the guarded update and its diagnosis share one transaction. A step holding the fence
     * is not waited for: the redrive stands down as `Raced` and changes nothing. Inside a caller's
     * ambient transaction the flip is a savepoint and the fence stays held until the outer commit;
     * `Redriven` then promises only what that transaction commits.
     */
    public function redrive(string $correlationId, string $messageId, bool $force = false): RedriveOutcome
    {
        try {
            // the fence key, read from the row itself: the type and correlation never change after the insert
            $workflowType = $this->connection->fetchOne(
                sprintf(
                    /* language=PostgreSQL */
                    "SELECT workflow_type FROM workflow_outbox WHERE correlation_id = :corr AND header->>'%s' = :mid", Header::MessageId->value),
                ['corr' => $correlationId, 'mid' => $messageId],
            );

            if ($workflowType === false) {
                return RedriveOutcome::NotFound;
            }
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        $mutation = new DbalRedriveMutation($this->connection);
        $messageIdClause = sprintf("o.header->>'%s' = :mid", Header::MessageId->value);

        return $mutation->underFence(
            new WorkflowId((string) $workflowType, $correlationId),
            static fn (): RedriveOutcome => $mutation->flip(
                'o.workflow_type = :type AND o.correlation_id = :corr AND '.$messageIdClause,
                ['type' => (string) $workflowType, 'corr' => $correlationId, 'mid' => $messageId],
                [],
                $force,
            )
                ? RedriveOutcome::Redriven
                : $mutation->diagnose('o.correlation_id = :corr AND '.$messageIdClause, ['corr' => $correlationId, 'mid' => $messageId]),
        );
    }

    public function cancelPending(WorkflowId $id, int $generation, array $spared): int
    {
        // a row a drain is publishing right now carries a lease still running, and a row a drain is
        // claiming right now is row-locked, this update blocking on it until the claim commits its
        // lease: either way the row is left alone. Keyed by the type and the correlation, never
        // another saga's rows
        $params = ['type' => $id->workflowType, 'corr' => $id->correlationId, 'generation' => $generation];
        $spare = '';
        foreach ($spared as $i => $entry) {
            // an undone entry's do stays owed to its undo; IS NOT DISTINCT FROM, since an ungrouped
            // row carries a NULL group that no equality would ever match
            $spare .= sprintf(' AND NOT (issued_from_state = :spared_state_%1$d AND effect_group IS NOT DISTINCT FROM :spared_group_%1$d)', $i);
            $params['spared_state_'.$i] = $entry->step;
            $params['spared_group_'.$i] = $entry->arm;
        }

        try {
            return (int) $this->connection->executeStatement(
                sprintf(
                    /* language=PostgreSQL */
                    "UPDATE workflow_outbox
                     SET status = '%s', processed_at = clock_timestamp()
                     WHERE workflow_type = :type AND correlation_id = :corr AND generation = :generation
                       AND status = '%s' AND purpose = '%s'
                       AND (claimed_until IS NULL OR claimed_until <= clock_timestamp())%s",
                    OutboxStatus::Cancelled->value,
                    OutboxStatus::Pending->value,
                    CommandPurpose::Forward->value,
                    $spare,
                ),
                $params,
                ['generation' => ParameterType::INTEGER],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    public function recallUndispatched(WorkflowId $id, int $generation, string $issuedFromState, string $effectGroup): int
    {
        // served by the group partial index, the literal status matching its predicate; the NULL
        // marker is what makes the count a proof, a row any relay ever took staying out of it
        try {
            return (int) $this->connection->executeStatement(
                sprintf(
                    /* language=PostgreSQL */
                    "UPDATE workflow_outbox
                     SET status = '%s', processed_at = clock_timestamp()
                     WHERE workflow_type = :type AND correlation_id = :corr AND effect_group = :effect_group
                       AND status = '%s' AND generation = :generation AND issued_from_state = :from_state
                       AND purpose = '%s' AND claimed_until IS NULL",
                    OutboxStatus::Cancelled->value,
                    OutboxStatus::Pending->value,
                    CommandPurpose::Forward->value,
                ),
                [
                    'type' => $id->workflowType,
                    'corr' => $id->correlationId,
                    'effect_group' => $effectGroup,
                    'generation' => $generation,
                    'from_state' => $issuedFromState,
                ],
                ['generation' => ParameterType::INTEGER],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    public function forwardClaims(WorkflowId $id, int $generation, string $issuedFromState, ?string $effectGroup): array
    {
        $params = [
            'type' => $id->workflowType,
            'corr' => $id->correlationId,
            'generation' => $generation,
            'from_state' => $issuedFromState,
            'effect_group' => $effectGroup,
        ];
        $types = ['generation' => ParameterType::INTEGER];

        try {
            // the lock takes the rows no relay has claimed, in id order and never skipping, so a claim
            // in flight is waited out and a locked row stays out of every claim until the step commits.
            // A claimed row is left alone: its marker never goes back to NULL, and the relay batch that
            // may still hold it would otherwise make the rollback wait on it, or deadlock
            $this->connection->fetchFirstColumn(
                sprintf(
                    /* language=PostgreSQL */
                    "SELECT id FROM workflow_outbox
                     WHERE workflow_type = :type AND correlation_id = :corr AND generation = :generation
                       AND issued_from_state = :from_state AND effect_group IS NOT DISTINCT FROM :effect_group
                       AND purpose = '%s' AND claimed_until IS NULL
                     ORDER BY id
                     FOR UPDATE",
                    CommandPurpose::Forward->value,
                ),
                $params,
                $types,
            );

            // then every row's marker, whatever its status: a published or dead-lettered row is a claim
            // the verdict must see, and a row that reads unclaimed here is one the lock above holds
            $markers = $this->connection->fetchFirstColumn(
                sprintf(
                    /* language=PostgreSQL */
                    "SELECT claimed_until FROM workflow_outbox
                     WHERE workflow_type = :type AND correlation_id = :corr AND generation = :generation
                       AND issued_from_state = :from_state AND effect_group IS NOT DISTINCT FROM :effect_group
                       AND purpose = '%s'
                     ORDER BY id",
                    CommandPurpose::Forward->value,
                ),
                $params,
                $types,
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return array_map(static fn (mixed $marker): bool => $marker !== null, $markers);
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
                /* language=PostgreSQL */
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
                /* language=PostgreSQL */
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
            /* language=PostgreSQL */
            <<<'SQL'
                WITH due AS (
                    SELECT id FROM workflow_outbox WHERE %s ORDER BY id LIMIT %d FOR UPDATE SKIP LOCKED
                ), moved AS (
                    DELETE FROM workflow_outbox WHERE id IN (SELECT id FROM due)
                    RETURNING id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, purpose, created_at
                )
                INSERT INTO workflow_outbox_archive (id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, purpose, created_at)
                SELECT id, workflow_type, correlation_id, bus, header, content, attempts, issued_from_state, issued_at_version, generation, evidence, purpose, created_at FROM moved
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
     * disposal the archive is empty and this is a no-op. Cannot race the live relay, which never touches
     * the archive: its only writer is the move `archivePublished()` makes during the cleanup.
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
