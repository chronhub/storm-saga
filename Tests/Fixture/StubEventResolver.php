<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Engine\EventResolver;

/**
 * A test EventResolver: returns a fixed alias + payload for any event.
 */
final readonly class StubEventResolver implements EventResolver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private ?string $alias = null,
        private array $payload = [],
    ) {}

    public function aliasFor(object $event): ?string
    {
        return $this->alias;
    }

    public function payloadFor(object $event): array
    {
        return $this->payload;
    }
}
