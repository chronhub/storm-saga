<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * A delivered event reached a living saga that can never consume it: no wait still reachable from
 * `$state` accepts `$eventClass`. The engine drops it instead of asking for a redelivery that could
 * only fail the same way, and this announcement is what keeps the drop from being silent.
 *
 * Two situations produce it, and the trail cannot tell them apart, deliberately: an event class this
 * definition never awaits at all, routed here because the framework's router subscribes to the union
 * every `#[WaitFor]` declares across all workflows and the correlation resolved to this instance; or
 * a duplicate of a wait the saga has left for good. Both mean the same thing to the engine, that the
 * retry will never help, and the pair only matters to a human reading the trail, who has the class
 * and the resting state right here to tell which it was.
 *
 * Reporting the drop as an EARLY arrival instead would be worse and quieter: the message would burn
 * its whole retry budget before landing in the dead-letter transport, where it would read as an
 * infrastructure failure rather than a routing fact.
 *
 * Telemetry only.
 */
final readonly class SagaOutcomeDiscarded implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  class-string  $eventClass  the delivered class, the half of the diagnosis a human needs
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $state,
        public string $eventClass,
    ) {}
}
