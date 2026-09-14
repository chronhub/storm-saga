<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Command;

use Storm\Contracts\Message\SerializablePayload;
use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;

/**
 * The promotion's wake-up: issued by the semaphore workflow in the same step that turned a queue entry
 * into a grant, riding the saga outbox so the wake-up is atomic with the slot's bookkeeping and
 * redelivered until handled. The handler delivers a {@see SemaphoreSlotGranted} to the waiter; a
 * delivery is checked against the current holder under the semaphore fence before the waiter advances.
 */
final readonly class GrantSlot implements SerializablePayload
{
    public function __construct(
        public string $resource,
        public string $waiterType,
        public string $waiterCorrelation,
        /** The grant's expiry, in {@see \Storm\Clock\PointInTime} storage form. */
        public string $expiresAt,
        public ?string $grantId = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'resource' => $this->resource,
            'waiter_type' => $this->waiterType,
            'waiter_correlation' => $this->waiterCorrelation,
            'expires_at' => $this->expiresAt,
            'grant_id' => $this->grantId,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            (string) $payload['resource'],
            (string) $payload['waiter_type'],
            (string) $payload['waiter_correlation'],
            (string) $payload['expires_at'],
            isset($payload['grant_id']) ? (string) $payload['grant_id'] : null,
        );
    }
}
