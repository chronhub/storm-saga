<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

/**
 * The renew's answer when the grant was still alive: the lease now runs to `$expiresAt`. The holder
 * keeps working.
 */
final readonly class Renewed
{
    public function __construct(
        /** The re-stamped expiry, in {@see \Storm\Clock\PointInTime} storage form. */
        public string $expiresAt,
    ) {}
}
