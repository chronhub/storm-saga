<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

use Storm\Saga\Attributes\Spawns;

/**
 * Emitted, after commit, when the crossing an indexed family's gate had rested was spent: every
 * member of every awaited family is terminal, and the conclusion absorbed while they still ran left
 * the wait through the edge its class routes. Telemetry only.
 *
 * The one trace that says a saga crossed on a REPLAY rather than on an arrival, so an operator
 * reading a history where the crossing sits later than the conclusion that caused it sees why. The
 * crossing itself carries the absorbed conclusion's causation id, the business fact; this names the
 * mechanism beside it.
 *
 * @see Spawns
 */
final readonly class SagaFamilyCrossingReplayed implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  class-string  $eventClass  the absorbed conclusion's class, all the crossing needed of it
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $waitKey,
        public string $eventClass,
    ) {}
}
