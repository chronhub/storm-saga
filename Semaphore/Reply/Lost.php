<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Reply;

/**
 * The renew's answer when the grant is gone: the sweep already expropriated it, or it was never held.
 * The caller no longer owns the resource and must stop the work or acquire again; renewing later
 * cannot resurrect an expropriated lease, that would let the expired holder and its promoted
 * successor run against the same slot.
 */
final readonly class Lost {}
