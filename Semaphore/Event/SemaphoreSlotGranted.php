<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Event;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Saga\Semaphore\Command\GrantSlot;

/**
 * Delivered to the WAITER when its slot is held: immediately by the client on a granted acquire, or
 * later by the {@see GrantSlot} handler when a release or the sweep promoted it. The waiter's wait
 * state declares this class among its events; `resource` says which semaphore answered, for the saga
 * that gates on more than one.
 */
final readonly class SemaphoreSlotGranted implements SerializablePayload
{
    public function __construct(
        public string $resource,
        /** The grant's expiry, in {@see \Storm\Clock\PointInTime} storage form. */
        public string $expiresAt,
    ) {}

    public function toPayload(): array
    {
        return [
            'resource' => $this->resource,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['resource'], (string) $payload['expires_at']);
    }
}
