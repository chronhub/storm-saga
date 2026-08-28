<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

use Storm\Saga\Child\ChildCanceller;

/**
 * Emitted when a cascade cancel is acknowledged WITHOUT touching its target: the row at the child
 * correlation is not a child at all, not THIS parent's child, or not the workflow the command
 * names. Canceling on identity alone kills an unrelated workflow, so the proof of parenthood is
 * verified on the row, and a failed proof is announced, never acted on.
 *
 * A missing or already-terminal child is NOT announced here: that is the cascade landing after the
 * race resolved itself, the quiet no-op both sides are designed to absorb.
 *
 * `$workflowType` and `$correlationId` are the PARENT's, since the cascade is an episode of the
 * parent's trail, named as every announcement names its saga so the history entry extracts them
 * structurally.
 *
 * `$generation` is the parent's claimed run when known; a skip path announces 0, the honest
 * unknown, since the parent may not even have a row yet.
 *
 * Telemetry only.
 *
 * @see ChildCanceller
 */
final readonly class SagaChildCancelSkipped implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $childWorkflowType,
        public string $childCorrelationId,
        public string $reason,
    ) {}
}
