<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

/**
 * No slot or queue place was stored for this acquire, and no later grant is owed.
 *
 * A declared queue bound reports `queueLimit`. A storage-budget refusal reports `stateLimitBytes`
 * with a null `queueLimit`; it does not claim that the configured queue length was reached.
 * The caller decides whether to fail its work or retry later.
 */
final readonly class Rejected
{
    public function __construct(
        public ?int $queueLimit,
        public ?int $stateLimitBytes = null,
    ) {}
}
