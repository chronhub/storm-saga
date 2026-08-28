<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;

/**
 * The acquire's answer when a slot is held NOW: the waiter may enter the resource until `$expiresAt`,
 * or longer by renewing. Handed back synchronously by `signalFor`, so the caller knows before its own
 * transaction moves on; the matching {@see SemaphoreSlotGranted} is also delivered to the waiter so a
 * saga resting on the wait state resolves the same way a promoted one does.
 */
final readonly class Granted
{
    public function __construct(
        /** The grant's expiry, in {@see \Storm\Clock\PointInTime} storage form. */
        public string $expiresAt,
    ) {}
}
