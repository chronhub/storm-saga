<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * The scheduled tick of the semaphore, and it is NOTHING but an open of the ledger: opening reaps,
 * expired grants are expropriated, lapsed queue entries dropped, freed slots promoted, and the
 * promotions' wake-up commands ride this step's outbox. Every verb already reaps lazily on its own
 * touch; this tick is the backstop for the semaphore nobody touches, so a crashed holder's slot never
 * outlives its TTL by more than one sweep interval.
 */
final readonly class SweepActivity implements Activity
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private Clock $clock,
    ) {}

    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        $ledger = SemaphoreLedger::open($this->clock->now(), $vars);

        return ActivityResult::success($ledger->vars(), $ledger->grants());
    }
}
