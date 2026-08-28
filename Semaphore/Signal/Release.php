<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Signal;

/**
 * Give the slot back, fire-and-forget: removes the waiter's grant, or its queue entry when the grant
 * raced the waiter's own abandonment, then promotes from the queue head. Idempotent: an unknown token
 * is a no-op, so a redelivered release cannot free someone else's slot. The release is the HOLDER's
 * duty on every exit path, success and compensation alike; the grant TTL only backstops the holder
 * that crashed before honoring it.
 */
final readonly class Release
{
    public function __construct(
        public string $waiterType,
        public string $waiterCorrelation,
    ) {}
}
