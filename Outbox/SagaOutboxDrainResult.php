<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

/**
 * What one `SagaOutboxRelay::drain()` pass did: how many commands were `published`, and how many were
 * `failed`, dead-lettered because undecodable or out of retry budget. Rows merely backed off for a later
 * retry count as neither.
 *
 * `published` counts the publications the pass made. A row whose claim lapsed mid-publish and that
 * another relay took again stays that relay's to mark, so the count can exceed the rows this pass
 * left `published`.
 *
 * @see SagaOutboxRelay::drain()
 */
final readonly class SagaOutboxDrainResult
{
    public function __construct(
        public int $published,
        public int $failed,
        /**
         * Whether the batch cap cut the drain short, so due work is still waiting.
         *
         * `0 published` was already the honest end-of-drain signal, and a FULL batch was
         * indistinguishable from it: the runbook that says drain before a topology change would read a
         * green line, swap the code, and leave the rest of the queue flying with yesterday's serialized
         * state. Read from the claim's own SIZE rather than probed: over-reading one row would claim it,
         * and its lease would withhold it from every other worker for as long as it runs.
         *
         * So a full batch says there is more, and a SHORT one says only that the cap did not cut this
         * run. What is left over but not due is the other half of the question, and it is answered
         * away from here, by {@see SagaOutboxRelay::countPending()}.
         */
        public bool $moreDue = false,
    ) {}
}
