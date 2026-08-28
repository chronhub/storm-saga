<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Signal;

use Storm\Saga\Semaphore\Reply\Granted;
use Storm\Saga\Semaphore\Reply\Queued;
use Storm\Saga\Semaphore\Reply\Rejected;

/**
 * Ask the semaphore for a slot, answered in the same fenced step through `signalFor`: the reply is
 * {@see Granted}, {@see Queued}, or {@see Rejected} when the queue is full. Idempotent by
 * construction: the token is the waiter's identity, so a redelivered acquire finds its own grant or
 * queue entry and gets the same answer back, never a second slot.
 *
 * `$grantTtlSeconds` overrides the semaphore's provisioned default for THIS grant; the lease starts
 * when the slot is granted, at acquire or at a later promotion, never at enqueue time.
 */
final readonly class Acquire
{
    public function __construct(
        public string $waiterType,
        public string $waiterCorrelation,
        public ?int $grantTtlSeconds = null,
    ) {}
}
