<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Engine\EffectProvenance;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The dead-letter capability of the saga's command outbox: read a failed command's provenance, flip
 * a published one to failed on the consumer's word, and send a dead-lettered one back into flight.
 * The settle, the failure listener, and the redrive surfaces ask for this half; the step's sealing
 * half is `WorkflowCommandStore`.
 *
 * @see WorkflowCommandStore
 */
interface FailedWorkflowCommands
{
    /**
     * The pairing input of a dead-letter settle, read back from the stored row by its sealed message id:
     * the issuing state, and whether another command of the SAME step, sharing the version marker, is
     * still alive as pending or published, the multi-command ambiguity. Null when the row is unknown or
     * predates the provenance columns with an empty `issued_from_state`; the caller treats unknown as
     * unpaired, never as a settle.
     *
     * Answers ONLY for a row still in the `failed` state, and the narrowing is the point. The reconcile
     * sweep reads its work list first and re-reads each row here afterwards; anything that returns the
     * row to flight between those two moments, such as an operator's redrive, would otherwise still be
     * paired, and the settle would compensate a step whose command is on its way to a handler again.
     * A debit refunded, then re-debited. Since the three real callers all mark `failed` before asking,
     * this costs them nothing; it only refuses to answer about a command that is no longer dead.
     *
     * The corollary to own: this is a dead-letter lookup, not a general provenance read. It stays mute
     * on a `published` row, and a future caller wanting the provenance of a command that SUCCEEDED needs
     * its own query rather than a widening of this one.
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function provenance(string $correlationId, string $messageId, int $generation): ?EffectProvenance;

    /**
     * The consumer-side dead-letter's durable trace: flip THE `published` row, found by its sealed
     * message id, to `failed`. It is the twin of the relay's own dead-letter, which marks by row id
     * inside the drain transaction; this one is called post-hoc by the failure listener when the command
     * was delivered fine but its HANDLER exhausted its retries. Only a `published` row flips: `pending`
     * is still the relay's, `cancelled` was recalled, `failed` is already there as an idempotent no-op.
     * The flipped row is what makes the settle crash-proof, since the maintenance reader's stranded
     * query re-derives it even when the listener's in-process settle never ran.
     *
     * @return bool whether a row flipped; false for an unknown id or a row not `published`
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function markFailed(string $correlationId, string $messageId, string $error, EffectEvidence $evidence = EffectEvidence::Unknown): bool;

    /**
     * Send a dead-lettered command back into flight: flip the row to `pending` so the relay publishes
     * it again, with a fresh attempt budget. The operator's repair for a command that died of a cause
     * OUTSIDE the saga, a handler not deployed, a broker misrouted, a bug since fixed; the only
     * alternative is canceling a saga that did nothing wrong.
     *
     * Four guards, all IN the UPDATE's predicate rather than read before it, so no window opens
     * between deciding and acting:
     *
     * - The row is still `failed`, since only a dead command is re-sent;
     * - Its evidence is {@see EffectEvidence::Uncommitted}, unless `$force`: an effect whose rollback
     *   nobody proved may be out there, and re-sending would DOUBLE it. Refusing is the default
     *   because the default has to be the safe choice;
     * - The saga still RUNS, since an effect in flight for a settled saga is an orphan nothing will
     *   ever receive;
     * - The row's generation is the saga's CURRENT one, so a command issued by an earlier run of a
     *   reusing correlation can never be re-sent into the run that replaced it.
     *
     * Two seams this rides rather than fights. The relay claims `pending` rows under
     * `FOR UPDATE SKIP LOCKED`, and this row is `failed` until the statement commits, so it enters
     * that claim already whole. And the reconcile sweep cannot settle around it, since `provenance()`
     * refuses to pair a row that left the dead-letter state; without that refusal this whole verb
     * would compensate and re-send the same command at once.
     *
     * @param  bool  $force  own the risk of a possibly-committed effect explicitly
     *
     * @throws SagaStorageFailure when the storage fails; the adapter wraps the driver's failure, cause chained
     */
    public function redrive(string $correlationId, string $messageId, bool $force = false): RedriveOutcome;
}
