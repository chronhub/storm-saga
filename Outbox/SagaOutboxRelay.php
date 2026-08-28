<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Message\Header;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\Engine;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * Drains `workflow_outbox`: claims the due, unsent rows via `FOR UPDATE SKIP LOCKED` so parallel relays
 * never double-send, rebuilds each command via the `MessageSerializer`, and hands it to the
 * `SagaCommandPublisher`. At-least-once: a row is marked `published` after a successful dispatch, in one
 * statement for the whole batch at the end of the drain transaction; it is NOT deleted here. The
 * `published` row lingers on purpose: it is the settle's pairing input and the durable command trail. A
 * consumer that later poisons the command flips this row from `published` to `failed` by its sealed id,
 * and `failIssuedEffect` reads its provenance. Disposal, delete or archive by age, is the cleanup's job
 * {@see \Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter::prune()}, never the relay's: disposing here would destroy the row the
 * settle needs before the consumer-side failure can arrive. A crash mid-loop re-sends, never silently
 * drops. It shares only a claim-loop shape with the event outbox relay; they encode different invariants,
 * this one having no ordering and no event upcasting, so they stay separate by design, not pending
 * unification.
 *
 * No inter-command ordering: rows drain `ORDER BY id` under `FOR UPDATE SKIP LOCKED`, so parallel relays
 * may dispatch two rows of the same correlation out of insertion order. A compensation-issued command is
 * causally after the forward command it undoes, but the outbox does not express that, so a workflow
 * needing command A to land before command B must not rely on this outbox for that ordering. Harmless
 * in the current design: forward and compensation are separated by a full async round-trip.
 *
 * The operator freeze gates the claim: a pending row whose saga is RUNNING and paused, by its own
 * stamp or its type's registry row, is left unclaimed until the resume, so the pause holds back the
 * commands a step committed but the relay had not yet dispatched. A settled instance's rows always
 * drain, which is what lets a cancel that passed through the freeze cascade to its children and
 * compensate.
 *
 * Outcomes per row:
 *
 * - Published: marked `published`, in the same transaction, batched; it lingers as the command trail
 *   and the settle's pairing input until the cleanup reaps it by age.
 *
 * - Transient, when publish threw: bump `attempts`, set `next_attempt_at` with exponential back-off;
 *   after `maxAttempts` it is dead-lettered.
 *
 * - Permanent: dead-lettered now, when the row can't be decoded due to a corrupt payload or unknown
 *   type, or when `publish()` threw an `UnrecoverableCommandDispatch` for no handler or an invalid
 *   command; retrying can't help, and a prompt dead-letter lets the post-commit `failIssuedEffect`
 *   compensation run sooner.
 *
 * Each row's outcome is written under its own savepoint. `publish()` shares this connection, so a
 * transport failure that is itself a failed statement, not merely a thrown PHP exception, leaves
 * Postgres refusing every further statement on the transaction until one is undone; without the
 * savepoint, the back-off or dead-letter write for THAT row would fail too, escape uncaught, and
 * abort the whole batch, publishing the earlier rows in this drain for real while rolling their
 * `published` mark back, a duplicate dispatch on the next run, and returning the poisoned row to
 * `pending` unchanged, so the same failure repeats forever on every drain. The rollback clears the
 * aborted state before the row's own write, so ONE bad row costs its own slot, never its neighbors'.
 *
 * @see OutboxStatus the status vocabulary; the drain SQL spells its hot-path transitions inline
 */
final readonly class SagaOutboxRelay
{
    public function __construct(
        private Connection $connection,
        private MessageSerializer $serializer,
        private SagaCommandPublisher $publisher,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $maxAttempts = 5,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $backoffBaseSeconds = 1,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $backoffMaxSeconds = 60,
        /**
         * After a dispatch dead-letters, signals the engine so the saga settles safely, a dead-letter
         * being equivalent to no commit. Optional so tests can construct the relay standalone; the
         * bundle autowires it in production. Signaled AFTER the drain transaction commits.
         */
        private ?Engine $engine = null,
    ) {}

    /**
     * @throws Exception on a DBAL failure of the claim or mark statements or the wrapping transaction; a
     *                   per-row decode or publish failure is handled inline via back-off or dead-letter,
     *                   each row wrapped in its own savepoint so a poisoned Postgres transaction, publish()
     *                   sharing this connection and failing its own statement included, rolls back to a
     *                   clean state before the row's bookkeeping write, rather than aborting the batch; a
     *                   row whose bookkeeping write still fails against that clean state is skipped and
     *                   left `pending`, never re-thrown, so ONE unrecoverable row costs a slot, not the drain
     * @throws Throwable when any other exception is thrown by the transaction's commit; a post-commit
     *                   settle's own failure, its storage, version, clock and serialization tails
     *                   included, never escapes the per-item isolation, the reconcile backstop
     *                   re-derives that very settle from the durable failed row
     */
    public function drain(int $batch = 100): SagaOutboxDrainResult
    {
        /** @var list<array{0: string, 1: string|null}> $deadLettered correlationId and sealed messageId pairs, signaled after the drain tx commits */
        $deadLettered = [];

        $result = $this->connection->transactional(function (Connection $connection) use ($batch, &$deadLettered): SagaOutboxDrainResult {
            $rows = $connection->fetchAllAssociative(
                /** @lang PostgreSQL */
                "SELECT id, workflow_type, correlation_id, bus, header, content, attempts
                 FROM workflow_outbox o
                 WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())
                   -- the operator freeze reaches what has not yet left: a pending command whose saga
                   -- is RUNNING and paused, by its own stamp or its type's registry row, stays the
                   -- outbox's until the resume. A settled instance's commands always flow, so a
                   -- cancel that passed THROUGH the freeze still cascades and compensates.
                   AND NOT EXISTS (
                       SELECT 1 FROM workflow_instances i
                       WHERE i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                         AND i.status = 'running'
                         AND (i.paused_at IS NOT NULL
                              OR EXISTS (SELECT 1 FROM workflow_pauses p WHERE p.workflow_type = i.workflow_type))
                   )
                 ORDER BY id
                 LIMIT :batch
                 FOR UPDATE SKIP LOCKED",
                ['batch' => $batch],
                ['batch' => ParameterType::INTEGER],
            );

            $failed = 0;

            /** @var list<int> $publishedIds marked `published` in ONE statement at the end of the batch */
            $publishedIds = [];

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $attempts = (int) $row['attempts'];
                $savepoint = 'saga_outbox_row_'.$id;

                try {
                    $connection->createSavepoint($savepoint);

                    try {
                        $pair = SerializedMessage::fromPairRow($row); // validated once: decoded header/content
                        $message = $this->serializer->deserialize(['header' => $pair->header, 'content' => $pair->content]);
                    } catch (Throwable $e) {
                        // never handed to a handler: the bytes could not even be turned back into a command
                        $connection->rollbackSavepoint($savepoint);
                        $this->deadLetter($connection, $id, $attempts, $e, EffectEvidence::Uncommitted);
                        $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                        $failed++;

                        continue;
                    }

                    try {
                        $this->publisher->publish($message, (string) $row['bus'], (string) $row['workflow_type']);
                    } catch (UnrecoverableCommandDispatch $e) {
                        // Permanent: no handler / invalid command; retrying can't help. Dead-letter now
                        // instead of burning the budget, which would also delay the failIssuedEffect settle.
                        // dispatch refused it outright: no handler ran, so no effect can exist. Rolled back
                        // FIRST: publish() shares this connection, so its own failed statement may have
                        // left the transaction aborted, and writing the dead-letter on top would fail too.
                        $connection->rollbackSavepoint($savepoint);
                        $this->deadLetter($connection, $id, $attempts + 1, $e, EffectEvidence::Uncommitted);
                        $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                        $failed++;

                        continue;
                    } catch (Throwable $e) {
                        $connection->rollbackSavepoint($savepoint);
                        $next = $attempts + 1;
                        if ($next >= $this->maxAttempts) {
                            // publish threw, and under a SYNC transport publish IS the handler: nothing here
                            // proves the effect never landed, so the settle must not assume it did not
                            $this->deadLetter($connection, $id, $next, $e, EffectEvidence::Unknown);
                            $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                            $failed++;
                        } else {
                            $this->retryLater($connection, $id, $next, $e);
                        }

                        continue;
                    }

                    $connection->releaseSavepoint($savepoint);
                    $publishedIds[] = $id;
                } catch (Throwable) {
                    // This row's own bookkeeping write failed even against the clean, rolled-back
                    // savepoint: an unrecoverable poison, not the transient one the rollback above
                    // already absorbs. Roll back once more so the row's partial state cannot bleed
                    // into the next iteration, and leave the row untouched (still `pending`) rather
                    // than risk a second failing write inside an already-poisoned block. A quiet skip
                    // here still ends the loop's progress on this one row, never on the batch: the
                    // liveness this savepoint exists for is the OTHER rows', which keep draining.
                    $connection->rollbackSavepoint($savepoint);
                }
            }

            $this->markPublished($connection, $publishedIds);

            // A full batch means the cap cut the drain, not that the queue is empty. Read from the
            // claim's own size rather than a second probe: over-reading one row would LOCK it under
            // FOR UPDATE and hold it from another worker, and a probe after the batch cannot see past
            // our own locks. It can say "more" over a queue that ended exactly on the cap, which costs
            // one extra run; the other direction costs a deploy over messages still flying.
            return new SagaOutboxDrainResult(count($publishedIds), $failed, count($rows) === $batch);
        });

        // Signal AFTER commit: the failed status is durable, and failIssuedEffect runs in its own fenced tx.
        // Whether it SETTLES depends on the evidence stamped above, read back with the provenance: only a
        // command proven never to have reached a handler compensates; the rest escalates, visibly.
        // Isolated per item: one throwing settle must not skip the batch's other sagas, and losing
        // the in-process signal loses nothing durable, the cleanup's reconcile re-derives the same
        // settle from the failed row on its next pass.
        if ($this->engine !== null) {
            foreach ($deadLettered as [$correlationId, $messageId]) {
                try {
                    $this->engine->failIssuedEffect($correlationId, failedMessageId: $messageId);
                } catch (Throwable) {
                    // the reconcile backstop owns it: the failed row is durable, the cleanup's
                    // next pass re-derives this very settle and reports the poison loud there
                }
            }
        }

        return $result;
    }

    /**
     * How many commands are still `pending`, whatever the reason this drain did not take them.
     *
     * The batch signal answers one question only, whether the cap cut the run short, and a SHORT batch
     * is not the same fact as an empty table: a command backing off from an EARLIER run is not due, so
     * no claim takes it and no full batch reports it, and the deployment gate that loops until a run
     * relays 0 reads that silence as a drained queue. This relay has a second reason of its own, and
     * the count carries it too: the claim skips a command whose saga is frozen, by its own stamp or by
     * its type's registry row, so a fleet-wide pause empties every batch while the work waits.
     *
     * Commands another worker holds under `SKIP LOCKED` count here as well, deliberately: to the
     * operator asking whether the outbox is empty, held, frozen and cooling are the same answer.
     *
     * Outside the drain's transaction, and a plain count: it claims no row and holds no lock, so it
     * cannot withhold work from another worker the way an over-read of the claim would.
     *
     * @throws Exception on a DBAL failure of the count
     */
    public function countPending(): int
    {
        return (int) $this->connection->fetchOne(
            /** @lang PostgreSQL */
            "SELECT count(*) FROM workflow_outbox WHERE status = 'pending'",
        );
    }

    /**
     * The sealed message id out of the row's raw header, best-effort: a corrupt header, the very reason
     * some rows dead-letter, yields null, and the settle signal goes out unpaired.
     */
    private function sealedMessageId(string $rawHeader): ?string
    {
        $header = json_decode($rawHeader, true);
        $id = is_array($header) ? ($header[Header::MessageId->value] ?? null) : null;

        return is_string($id) ? $id : null;
    }

    /**
     * Mark the batch's dispatched rows `published` in ONE statement, inside the drain transaction and
     * atomic with the dispatches. The row lingers until the cleanup reaps it by age {@see \Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter::prune()}.
     * `processed_at` stamps the publication instant. Failed rows never reach this list; dead-letters
     * keep their own `failed` status.
     *
     * @param  list<int>  $publishedIds
     *
     * @throws Exception on a DBAL failure of the mark statement
     */
    private function markPublished(Connection $connection, array $publishedIds): void
    {
        if ($publishedIds === []) {
            return;
        }

        $connection->executeStatement(
            /** @lang PostgreSQL */
            "UPDATE workflow_outbox SET status = 'published', processed_at = clock_timestamp() WHERE id IN (:ids)",
            ['ids' => $publishedIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * @throws Exception on a DBAL failure
     */
    private function retryLater(Connection $connection, int $id, int $attempts, Throwable $error): void
    {
        $connection->executeStatement(
            /** @lang PostgreSQL */
            'UPDATE workflow_outbox
             SET attempts = :attempts, last_error = :error,
                 next_attempt_at = clock_timestamp() + make_interval(secs => :secs)
             WHERE id = :id',
            ['attempts' => $attempts, 'error' => AuditDigest::digest($error), 'secs' => $this->backoffSeconds($attempts), 'id' => $id],
            ['attempts' => ParameterType::INTEGER, 'secs' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Dead-letter the row, stamping what is KNOWN about its effect. `$evidence` is the caller's, because
     * only the call site knows how far the command got: a row that could not be decoded, or one dispatch
     * refused for want of a handler, never reached a handler at all and its effect is impossible; a
     * publish that exhausted its retries did reach one under a sync transport, so nothing is proven.
     * The stamp is durable so the cleanup's reconcile settles on the same footing hours later.
     *
     * @throws Exception on a DBAL failure
     */
    private function deadLetter(Connection $connection, int $id, int $attempts, Throwable $error, EffectEvidence $evidence): void
    {
        $connection->executeStatement(
            /** @lang PostgreSQL */
            "UPDATE workflow_outbox
             SET status = 'failed', attempts = :attempts, last_error = :error, evidence = :evidence, processed_at = clock_timestamp()
             WHERE id = :id",
            ['attempts' => $attempts, 'error' => AuditDigest::digest($error), 'evidence' => $evidence->value, 'id' => $id],
            ['attempts' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * @return int<1, max> back-off seconds: exponential `base·2^(n-1)`, capped. The cap applies
     *                     to the FLOAT, then the cast: a large attempt count overflows `2^(n-1)`
     *                     past what an int represents, and casting first collapsed the capped
     *                     exponential to the one-second floor, a hammer against a downstream
     *                     already failing.
     */
    private function backoffSeconds(int $attempts): int
    {
        $seconds = $this->backoffBaseSeconds * (2 ** ($attempts - 1));

        return max(1, (int) min($this->backoffMaxSeconds, $seconds));
    }
}
