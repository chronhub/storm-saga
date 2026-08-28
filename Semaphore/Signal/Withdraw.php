<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Signal;

/**
 * The waiter abandons its claim, fire-and-forget: the queue-side intent twin of {@see Release},
 * issued by the waiter whose own patience deadline fired. It removes the queue entry, AND the grant
 * when a promotion raced the abandonment in flight, so a withdrawn waiter never leaves a ghost slot
 * behind until the TTL. Idempotent: an unknown token is a no-op.
 */
final readonly class Withdraw
{
    public function __construct(
        public string $waiterType,
        public string $waiterCorrelation,
    ) {}
}
