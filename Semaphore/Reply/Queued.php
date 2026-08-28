<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;
use Storm\Saga\Semaphore\Signal\Withdraw;

/**
 * The acquire's answer when every slot is taken: the waiter holds a queue place and will be told by a
 * {@see SemaphoreSlotGranted} delivery when a release or the sweep promotes it. The queue entry expires
 * on its own TTL as the backstop; the waiter's OWN patience deadline firing first should
 * {@see Withdraw} rather than leave a ghost place.
 */
final readonly class Queued
{
    public function __construct(
        /** The 1-based place in the queue at the time of the answer, informational only. */
        public int $position,
    ) {}
}
