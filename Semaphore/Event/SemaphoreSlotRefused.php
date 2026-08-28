<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Event;

use Storm\Contracts\Message\SerializablePayload;

/**
 * Delivered to the WAITER when its acquire was rejected outright, the queue at its declared bound:
 * nothing is pending, no later grant will come, and the waiter's graph decides what a full house
 * means. The refusal's twin of {@see SemaphoreSlotGranted}, declared on the same wait state so the
 * saga always advances instead of discovering the rejection by its own deadline.
 */
final readonly class SemaphoreSlotRefused implements SerializablePayload
{
    public function __construct(
        public string $resource,
    ) {}

    public function toPayload(): array
    {
        return ['resource' => $this->resource];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['resource']);
    }
}
