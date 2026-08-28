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
 * - `Cancelled`: recalled by an aborting settle before the relay claimed it, never dispatched;
 *   audit-only, prunable purely by age.
 *
 * @see SagaOutboxRelay drives the pending to published or failed transitions inline in its drain SQL
 * @see WorkflowOutboxWriter::markFailed() the consumer-side published to failed flip
 * @see WorkflowOutboxWriter::cancelPending() the pending to cancelled recall
 */
enum OutboxStatus: string
{
    case Pending = 'pending';

    case Published = 'published';

    case Failed = 'failed';

    case Cancelled = 'cancelled';
}
