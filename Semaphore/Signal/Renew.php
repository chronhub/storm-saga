<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Signal;

use Storm\Saga\Semaphore\Reply\Lost;
use Storm\Saga\Semaphore\Reply\Renewed;

/**
 * The slow holder's relief valve, answered through `signalFor`: re-stamp the grant's expiry from now
 * so a long-but-alive work is not expropriated by the sweep. The reply is honest about the race it
 * exists for: {@see Renewed} with the new expiry, or {@see Lost} when the grant was already swept, at
 * which point the caller no longer holds the resource and must stop or re-acquire.
 */
final readonly class Renew
{
    public function __construct(
        public string $waiterType,
        public string $waiterCorrelation,
        public ?int $grantTtlSeconds = null,
    ) {}
}
