<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

use Storm\Saga\Child\ChildSpawner;

/**
 * Emitted when a spawn is acknowledged WITHOUT a birth because its parent can no longer own a
 * child: the parent row is gone, terminal, or not the workflow the command claims. This is the
 * normal outcome of the cancel-versus-spawn race, a parent canceled while its spawn command was
 * in flight, so it is announced, never dead-lettered: a child must not be born orphan, and a
 * terminal parent cannot be settled twice.
 *
 * `$generation` is the parent's claimed run when known; a skip path announces 0, the honest
 * unknown, since the parent may not even have a row yet.
 *
 * Telemetry only.
 *
 * @see ChildSpawner
 */
final readonly class SagaChildSpawnSkipped implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * `$workflowType` and `$correlationId` are the PARENT's, since the skipped spawn is an episode
     * of the parent's trail and the child was never born, named as every announcement names its
     * saga so the history entry extracts them structurally.
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $childWorkflowType,
        public string $slot,
        public string $reason,
    ) {}
}
