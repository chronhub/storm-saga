<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Saga\Engine\EventResolver;

use function get_object_vars;

/**
 * The runtime's default event resolver for a world without an alias map: no event carries an
 * alias, and a payload is the event's public properties. The right shape for samples and app tests
 * whose waits match by class; a deployment whose waits match by ALIAS must hand the runtime its
 * real resolver instead, or those waits will never see their events.
 */
final readonly class PlainEventResolver implements EventResolver
{
    public function aliasFor(object $event): ?string
    {
        return null;
    }

    public function payloadFor(object $event): array
    {
        return get_object_vars($event);
    }
}
