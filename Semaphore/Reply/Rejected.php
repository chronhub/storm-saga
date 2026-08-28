<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

/**
 * The acquire's answer when the queue is at its declared bound: nothing was stored, and nothing will be
 * delivered later; the CALLER decides what a full house means, failing its work or coming back later.
 * The bound is the semaphore's bounded-by-design stance: an incident downstream must not grow one row's
 * vars without limit.
 */
final readonly class Rejected
{
    public function __construct(
        /** The provisioned `max_queue` the acquire hit. */
        public int $queueLimit,
    ) {}
}
