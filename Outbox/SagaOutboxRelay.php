<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Storm\Contracts\Random\Jitter;
use Storm\Message\Header;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\Engine;
use Storm\Serializer\MessageSerializer;
use Storm\Serializer\SerializedMessage;
use Storm\Support\Error\AuditDigest;
use Storm\Support\Error\TransientFailure;
use Storm\Support\Random\NativeJitter;
use Throwable;

/**
 * Drains `workflow_outbox` in two transactions, and commits the first before anything is published. The
 * claim takes the due rows via `FOR UPDATE SKIP LOCKED`, spends one attempt of each row's budget and sets
 * its claim marker to a lease; the dispatch then rebuilds each command via the `MessageSerializer`, hands
 * it to the `SagaCommandPublisher`, and marks the dispatched rows `published` in one statement at the end
 * of its own transaction. The row is NOT deleted here. The `published` row lingers on purpose: it is the
 * settle's pairing input and the durable command trail. A consumer that later poisons the command flips
 * this row from `published` to `failed` by its sealed id, and `failIssuedEffect` reads its provenance.
 * Disposal, delete or archive by age, is the cleanup's job
 * {@see \Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter::prune()}, never the relay's: disposing here would destroy the row the
 * settle needs before the consumer-side failure can arrive. It shares only a claim-loop shape with the
 * event outbox relay; they encode different invariants, this one having no ordering and no event
 * upcasting, so they stay separate by design, not pending unification.
 *
 * At-least-once, never silently dropped: a drain lost after its dispatch leaves every row it took
 * `pending`, its marker set and its lease running. No relay takes a row while its lease runs, so parallel
 * relays never double-send within it; once it lapses, the next relay sends the row again under the same
 * sealed message id, which the consumer's inbox dedups. A lease shorter than the slowest dispatch lets a
 * second relay send a row the first is still publishing, and the first relay's outcome then leaves the
 * row to the second.
 *
 * No inter-command ordering: rows drain `ORDER BY id` under `FOR UPDATE SKIP LOCKED`, so parallel relays
 * may dispatch two rows of the same correlation out of insertion order. A compensation-issued command is
 * causally after the forward command it undoes, but the outbox does not express that, so a workflow
 * needing command A to land before command B must not rely on this outbox for that ordering. An undo
 * can reach its handler first: a rollback leaves an undone step's pending commands beside its undo,
 * and an arm undone on the spot has its command still traveling, which is why every compensation
 * tolerates arriving before the do it reverses.
 *
 * The operator freeze gates the claim: a pending row whose saga is RUNNING and paused, by its own
 * stamp or its type's registry row, is left unclaimed until the resume, so the pause holds back the
 * commands a step committed but the relay had not yet dispatched. A settled instance's rows always
 * drain, which is what lets a cancel that passed through the freeze cascade to its children and
 * compensate.
 *
 * Outcomes per row:
 *
 * - Published: marked `published` at the end of the dispatch transaction, batched, and only while the
 *   row is still `pending` under this claim's lease, since no lock holds it during the publish; it
 *   lingers as the command trail and the settle's pairing input until the cleanup reaps it by age.
 *
 * - Transient, when publish threw: the claim already spent the attempt; set `next_attempt_at` with
 *   exponential back-off, end the lease, leave the row `pending`, and report the outage after the
 *   commit as {@see SagaOutboxDrainIncomplete}, so a scheduler reads a non-zero exit rather than a clean
 *   run over a stopped command lane. After `maxAttempts` claims the row is dead-lettered instead, unless
 *   {@see \Storm\Support\Error\TransientFailure} finds the transport or the database named in the
 *   cause chain: the budget measures the BROKER's health, and a dead-lettered command is one no relay
 *   sends again, so spending it on an outage would settle or compensate a saga whose command was only
 *   ever withheld.
 *
 * - Permanent: dead-lettered now, when the row can't be decoded due to a corrupt payload or unknown
 *   type, or when `publish()` threw an `UnrecoverableCommandDispatch` for no handler or an invalid
 *   command; retrying can't help, and a prompt dead-letter lets the post-commit `failIssuedEffect`
 *   compensation run sooner. An explicit `RejectedCommandExecution` also stops retries immediately,
 *   even when its cause is infrastructure-related, but retains unknown effect evidence because a
 *   handler may have run. It cannot authorize compensation on its own.
 *
 * The claim marker, `claimed_until`, is set by the claim before the publish, and each outcome restamps it
 * to its own instant, which ends the lease: a row a relay took, whatever came of it, can never again pass
 * for one no relay touched, which is what an arm's proving recall reads, and the abort's recall never
 * enters a row whose lease still runs. Every outcome write, the mark included, applies only to a row
 * still `pending` and still under the lease this claim set, which the claim returns with each row. An
 * outcome arriving after its lease lapsed, when another relay may have taken the row again, leaves
 * that relay's lease standing: the late publish is a duplicate the consumer's inbox dedups, and the
 * abort's recall keeps reading the row as taken.
 *
 * Each row's outcome is written under its own savepoint. `publish()` shares this connection, so a
 * transport failure that is itself a failed statement, not merely a thrown PHP exception, leaves
 * Postgres refusing every further statement on the transaction until one is undone; without the
 * savepoint, the back-off or dead-letter write for THAT row would fail too, escape uncaught, and
 * abort the whole batch, publishing the earlier rows in this drain for real while rolling their
 * `published` mark back, a duplicate dispatch once their leases lapse, and leaving the poisoned row
 * without its back-off, so the same failure comes back at every lapse. The rollback clears the
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
         * How long a claim keeps its rows from every other relay, in seconds: longer than the batch
         * size times the slowest publish, since every row of a batch waits under the one lease until
         * the batch is marked, and short enough that the rows of a drain lost after its dispatch go to
         * the next relay soon after. The bundle sets it from `claim_lease_seconds`.
         */
        private int $claimLeaseSeconds = 300,
        /**
         * After a dispatch dead-letters, signals the engine so the saga settles safely, a dead-letter
         * being equivalent to no commit. Optional so tests can construct the relay standalone; the
         * bundle autowires it in production. Signaled AFTER the drain transaction commits.
         */
        private ?Engine $engine = null,
        /**
         * Where a failed dispatch leaves its line, `storm.saga.publish_failed` at warning with the
         * workflow, the correlation, the attempt spent and the budget: the outage is otherwise a
         * non-zero exit and two columns on a row. Null logs nothing, for standalone use.
         */
        private ?LoggerInterface $logger = null,
        private Jitter $jitter = new NativeJitter,
    ) {}

    /**
     * @throws Exception on a DBAL failure of the claim or mark statements or of either transaction; a
     *                   per-row decode or publish failure is handled inline via back-off or dead-letter,
     *                   each row wrapped in its own savepoint so a poisoned Postgres transaction, publish()
     *                   sharing this connection and failing its own statement included, rolls back to a
     *                   clean state before the row's bookkeeping write, rather than aborting the batch; a
     *                   row whose bookkeeping write still fails against that clean state is skipped and
     *                   left `pending` under its claim, never re-thrown, so ONE unrecoverable row costs a
     *                   slot, not the drain
     * @throws SagaOutboxDrainIncomplete on a transient dispatch failure, thrown after committing the
     *                                   progress made so far, which it carries in its `progress`, and after the
     *                                   dead-letters' settles have run; the first failure is its `previous`
     * @throws Throwable when any other exception is thrown by the transaction's commit; a post-commit
     *                   settle's own failure, its storage, version, clock and serialization tails
     *                   included, never escapes the per-item isolation, the reconcile backstop
     *                   re-derives that very settle from the durable failed row
     */
    public function drain(int $batch = 100): SagaOutboxDrainResult
    {
        $claimed = $this->claim($batch);
        if ($claimed === []) {
            return new SagaOutboxDrainResult(0, 0, false);
        }

        /** @var list<array{0: string, 1: string|null}> $deadLettered correlationId and sealed messageId pairs, signaled after the drain tx commits */
        $deadLettered = [];

        $transient = null;

        $result = $this->connection->transactional(function (Connection $connection) use ($claimed, $batch, &$deadLettered, &$transient): SagaOutboxDrainResult {
            $failed = 0;

            /** @var array<int, string> $published each published row's lease, marked in ONE statement at the end of the batch */
            $published = [];

            foreach ($claimed as $row) {
                $id = (int) $row['id'];
                $claims = (int) $row['attempts']; // this claim included: the budget counts claims
                $lease = (string) $row['lease']; // this claim's own lease, which every outcome must still find
                $savepoint = 'saga_outbox_row_'.$id;

                try {
                    $connection->createSavepoint($savepoint);

                    try {
                        $pair = SerializedMessage::fromPairRow($row); // validated once: decoded header/content
                        $message = $this->serializer->deserialize(['header' => $pair->header, 'content' => $pair->content]);
                    } catch (Throwable $e) {
                        // never handed to a handler: the bytes could not even be turned back into a command
                        $connection->rollbackSavepoint($savepoint);
                        if ($this->deadLetter($connection, $id, $lease, $e, EffectEvidence::Uncommitted)) {
                            $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                            $failed++;
                        }

                        continue;
                    }

                    try {
                        $this->publisher->publish($message, (string) $row['bus'], (string) $row['workflow_type']);
                    } catch (UnrecoverableCommandDispatch|RejectedCommandExecution $e) {
                        // Roll back before recording the refusal because dispatch can leave this transaction
                        // aborted. A permanent execution refusal says nothing about effects outside it.
                        $connection->rollbackSavepoint($savepoint);
                        if ($this->deadLetter($connection, $id, $lease, $e, $e instanceof RejectedCommandExecution ? EffectEvidence::Unknown : EffectEvidence::Uncommitted)) {
                            $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                            $failed++;
                        }

                        continue;
                    } catch (Throwable $e) {
                        $connection->rollbackSavepoint($savepoint);
                        if ($claims >= $this->maxAttempts && ! TransientFailure::behind($e)) {
                            // Out of attempts, and nothing in the cause chain names the transport or the
                            // database: the failure is the command's own. Publish threw, and under a SYNC
                            // transport publish IS the handler, so nothing here proves the effect never
                            // landed and the settle must not assume it did not.
                            if ($this->deadLetter($connection, $id, $lease, $e, EffectEvidence::Unknown)) {
                                $deadLettered[] = [(string) $row['correlation_id'], $this->sealedMessageId((string) $row['header'])];
                                $failed++;
                            }
                        } else {
                            $this->retryLater($connection, $id, $lease, $claims, $e);
                            $this->logPublishFailure((string) $row['workflow_type'], (string) $row['correlation_id'], $claims, $e);
                            $transient ??= $e; // remember the first outage to surface after the commit
                        }

                        continue;
                    }

                    $connection->releaseSavepoint($savepoint);
                    $published[$id] = $lease;
                } catch (Throwable) {
                    // This row's own bookkeeping write failed even against the clean, rolled-back
                    // savepoint: an unrecoverable poison, not the transient one the rollback above
                    // already absorbs. Roll back once more so the row's partial state cannot bleed
                    // into the next iteration, and leave the row as its claim left it, `pending` with
                    // its attempt spent and its lease running, rather than risk a second failing write
                    // inside an already-poisoned block; the next relay takes it once the lease lapses.
                    // A quiet skip here still ends the loop's progress on this one row, never on the
                    // batch: the liveness this savepoint exists for is the OTHER rows', which keep draining.
                    $connection->rollbackSavepoint($savepoint);
                }
            }

            $this->markPublished($connection, $published);

            // A full batch means the cap cut the drain, not that the queue is empty. Read from the
            // claim's own size rather than a second probe: over-reading one row would claim it and
            // hold it from another worker for a whole lease, and a probe after the batch cannot see
            // past our own claims. It can say "more" over a queue that ended exactly on the cap, which
            // costs one extra run; the other direction costs a deploy over messages still flying.
            return new SagaOutboxDrainResult(count($published), $failed, count($claimed) === $batch);
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

        if ($transient !== null) {
            // Progress + the bumped attempts are committed, and the settles have run; now surface the
            // outage so the run retries, carrying the committed counts; fail forward, the work done
            // must show. Last, after the settles, so an outage never withholds a dead letter's own
            // compensation.
            throw SagaOutboxDrainIncomplete::after($result, $transient);
        }

        return $result;
    }

    /**
     * Take up to `$batch` due rows and commit the take before any of them is published: each row spends
     * one attempt of its budget and carries a lease in its claim marker, so a drain lost after its
     * dispatch leaves the row `pending` yet marked taken, and no other relay takes it while the lease
     * runs. The rows come back in `id` order, the order the dispatch sends them in.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Exception on a DBAL failure of the claim or its transaction
     */
    private function claim(int $batch): array
    {
        $lease = $this->claimLeaseSeconds;
        $rows = $this->connection->transactional(static fn (Connection $connection): array => $connection->fetchAllAssociative(
            /* language=PostgreSQL */
            "WITH due AS (
                 SELECT id
                 FROM workflow_outbox o
                 WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())
                   -- a lease still running is another relay's dispatch under way; a lapsed one is a
                   -- drain that was lost, whose rows are due again
                   AND (claimed_until IS NULL OR claimed_until <= clock_timestamp())
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
                 FOR UPDATE SKIP LOCKED
             )
             UPDATE workflow_outbox w
             SET attempts = w.attempts + 1, claimed_until = clock_timestamp() + make_interval(secs => :lease)
             FROM due
             WHERE w.id = due.id
             RETURNING w.id, w.workflow_type, w.correlation_id, w.bus, w.header, w.content, w.attempts,
                 -- the lease every outcome compares back, rendered as its UTC wall time with a fixed
                 -- +00: the session's DateStyle and TimeZone never enter it, whereas the column's own
                 -- text follows them, down to an abbreviation another zone shares
                 to_char(w.claimed_until AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US+00') AS lease",
            ['batch' => $batch, 'lease' => $lease],
            ['batch' => ParameterType::INTEGER, 'lease' => ParameterType::INTEGER],
        ));

        // RETURNING keeps no order; the dispatch sends in the claim's `id` order
        usort($rows, static fn (array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);

        return $rows;
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
     * Commands another worker holds under its claim count here as well, deliberately: to the
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
            /* language=PostgreSQL */
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
     * Mark the batch's dispatched rows `published` in ONE statement, inside the dispatch transaction and
     * atomic with the dispatches. The row lingers until the cleanup reaps it by age {@see \Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter::prune()}.
     * `processed_at` stamps the publication instant. Failed rows never reach this list; dead-letters
     * keep their own `failed` status.
     *
     * @param  array<int, string>  $leases  each published row's id and the lease its claim set
     *
     * @throws Exception on a DBAL failure of the mark statement
     */
    private function markPublished(Connection $connection, array $leases): void
    {
        if ($leases === []) {
            return;
        }

        $rows = [];
        $parameters = [];
        $types = [];
        foreach ($leases as $id => $lease) {
            $index = count($rows);
            $rows[] = sprintf('(CAST(:id_%1$d AS bigint), CAST(:lease_%1$d AS timestamptz))', $index);
            $parameters['id_'.$index] = $id;
            $parameters['lease_'.$index] = $lease;
            $types['id_'.$index] = ParameterType::INTEGER;
        }

        $connection->executeStatement(
            /* language=PostgreSQL */
            "UPDATE workflow_outbox w
             SET status = 'published', processed_at = clock_timestamp(), claimed_until = clock_timestamp()
             FROM (VALUES ".implode(', ', $rows).") AS mine (id, lease)
             WHERE w.id = mine.id AND w.claimed_until = mine.lease AND w.status = 'pending'",
            $parameters,
            $types,
        );
    }

    /**
     * @throws Exception on a DBAL failure
     */
    private function retryLater(Connection $connection, int $id, string $lease, int $claims, Throwable $error): void
    {
        $connection->executeStatement(
            /* language=PostgreSQL */
            "UPDATE workflow_outbox
             SET last_error = :error, claimed_until = clock_timestamp(),
                 next_attempt_at = clock_timestamp() + make_interval(secs => :secs)
             WHERE id = :id AND status = 'pending' AND claimed_until = CAST(:lease AS timestamptz)",
            ['error' => AuditDigest::digest($error), 'secs' => $this->backoffSeconds($claims), 'id' => $id, 'lease' => $lease],
            ['secs' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * Dead-letter the row, stamping what is KNOWN about its effect. `$evidence` is the caller's, because
     * only the call site knows how far the command got: a row that could not be decoded, or one dispatch
     * refused for want of a handler, never reached a handler at all and its effect is impossible; a
     * publish that exhausted its retries did reach one under a sync transport, so nothing is proven.
     * The stamp is durable so the cleanup's reconcile settles on the same footing hours later. Returns
     * true when the row was still `pending` under this claim's lease and is now `failed`, the one case
     * whose settle is owed.
     *
     * @throws Exception on a DBAL failure
     */
    private function deadLetter(Connection $connection, int $id, string $lease, Throwable $error, EffectEvidence $evidence): bool
    {
        return (int) $connection->executeStatement(
            /* language=PostgreSQL */
            "UPDATE workflow_outbox
             SET status = 'failed', last_error = :error, evidence = :evidence, processed_at = clock_timestamp(),
                 claimed_until = clock_timestamp()
             WHERE id = :id AND status = 'pending' AND claimed_until = CAST(:lease AS timestamptz)",
            ['error' => AuditDigest::digest($error), 'evidence' => $evidence->value, 'id' => $id, 'lease' => $lease],
            ['id' => ParameterType::INTEGER],
        ) === 1;
    }

    /**
     * Equal jitter spreads retries across the upper half of the capped exponential window.
     * The cap applies before the integer cast to preserve large attempt counts.
     *
     * @return int<1, max>
     */
    private function backoffSeconds(int $attempts): int
    {
        $seconds = $this->backoffBaseSeconds * (2 ** ($attempts - 1));
        $window = max(1, (int) min($this->backoffMaxSeconds, $seconds));

        // the port draws inside the range it is asked for, and this range never starts below one
        /** @var int<1, max> $seconds */
        $seconds = $this->jitter->between(max(1, intdiv($window, 2)), $window);

        return $seconds;
    }

    // fail-open, as every observation around a commit: a logger that throws must not undo the back-off
    private function logPublishFailure(string $workflowType, string $correlationId, int $attempts, Throwable $failure): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->warning('storm.saga.publish_failed', [
                'relay' => 'saga',
                'workflow_type' => $workflowType,
                'correlation_id' => $correlationId,
                'attempts' => $attempts,
                'max_attempts' => $this->maxAttempts,
                'error' => AuditDigest::digest($failure),
            ]);
        } catch (Throwable) {
            // the observation may lose its line; it may never cost the relay its progress
        }
    }
}
