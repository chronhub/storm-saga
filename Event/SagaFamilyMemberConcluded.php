<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

use Storm\Saga\Attributes\Spawns;

/**
 * Emitted, after commit, when a conclusion landed on an indexed spawn family's awaited wait while
 * the family is still incomplete: the event's vars landed and the saga rested in place. Telemetry
 * only.
 *
 * The three counts say WHY it waits, so an operator watching a quiet family reads whether a spawn
 * never landed or a member never concluded:
 *
 * - `expected`, the members the fan-out's own write declared.
 * - `spawned`, the members ever minted, per the durable registry.
 * - `living`, the members still running.
 *
 * @see Spawns
 */
final readonly class SagaFamilyMemberConcluded implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $waitKey,
        public string $family,
        public int $expected,
        public int $spawned,
        public int $living,
    ) {}
}
