<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a step halts the saga; its status becomes `halted`, a dead end reached
 * when:
 *
 * - A necessary transition was missing.
 *
 * - A handler gave up.
 *
 * - The global deadline or cap stopped it with nothing safe to drive or undo.
 *
 * - A rollback found nothing eligible to undo.
 *
 * `$state` is where it stopped. Telemetry only. It records where the saga stopped, not why.
 *
 * @see SagaStarted
 */
final readonly class SagaHalted implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $state,
    ) {}
}
