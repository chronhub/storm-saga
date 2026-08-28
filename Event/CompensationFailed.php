<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a single compensating activity fails during a rollback, whether it threw
 * or returned an `ActivityResult::failure`; both are treated identically. The engine keeps
 * compensating the remaining steps on a best-effort basis, so a saga can end `compensated` with one
 * of these flagged; it means a manual reconciliation is needed for `$state`.
 */
final readonly class CompensationFailed implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $state,
        /**
         * The failure reason: an activity's own failure text verbatim, or for a throw the audit
         * digest, class-prefixed, cause-chained, bounded and scrubbed, since the reason persists
         * in `workflow_history` and is served over the ops HTTP surface.
         */
        public string $error,
    ) {}
}
