<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Testing\Fixture;

use Storm\Contracts\Message\SerializablePayload;

/**
 * The checkout sample's outgoing command, serializable so the capturing outbox stores it the way a
 * durable row would.
 */
final readonly class ReserveInventory implements SerializablePayload
{
    public function __construct(
        public string $orderId,
    ) {}

    public function toPayload(): array
    {
        return ['orderId' => $this->orderId];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['orderId']);
    }
}
