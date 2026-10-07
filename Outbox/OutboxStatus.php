<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

/**
 * The lifecycle status of a `workflow_outbox` row: THE status vocabulary, shared by the writer, the
 * schema, and the retention and reconcile queries so a mistyped literal cannot silently select zero
 * rows.
 *
 * - `Pending`: written by the step, not yet claimed; the relay's input via the hot partial index.
 *
 * - `Published`: relayed successfully; audit-only from that moment, prunable purely by age.
 *
 * - `Failed`: dead-lettered, either by the relay mid-drain or post-hoc by the consumer-side failure
 *   listener. While its saga still RUNS it is the reconcile input, since `strandedByFailedEffect`
 *   re-derives a lost settle from it, so it must survive any prune; once the saga settled or its row is
 *   gone it is forensic audit, prunable by age.
 *
 * - `Cancelled`: recalled before any relay published it. The status alone never certifies the command
 *   never left, its claim marker does: a row cancelled with no marker was never taken by a relay, the
 *   non-event an arm's proving recall and a rollback's recall read, while an abort's recall also stops
 *   a row a relay tried and released. Audit-only, prunable purely by age.
 *
 * @see SagaOutboxRelay drives the pending to published or failed transitions inline in its drain SQL
 * @see WorkflowOutboxWriter::markFailed() the consumer-side published to failed flip
 * @see WorkflowOutboxWriter::cancelPending() the abort's pending to cancelled recall
 * @see WorkflowOutboxWriter::recallUndispatched() the arm's proving recall
 */
enum OutboxStatus: string
{
    case Pending = 'pending';

    case Published = 'published';

    case Failed = 'failed';

    case Cancelled = 'cancelled';
}
