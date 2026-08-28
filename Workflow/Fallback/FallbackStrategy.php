<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Fallback;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;
use Throwable;

/**
 * A replacement for a failed or circuit-open activity: it produces an alternative `ActivityResult` so the
 * saga can degrade gracefully rather than abort. A `Success` result salvages the step, and the saga takes
 * its `Success` transition carrying the fallback's vars and commands; anything else means the fallback did
 * not salvage it, so the engine tries the next one in the chain, else the `Failure` transition.
 *
 * Same shape as `Activity::run()` on purpose: a fallback is another way to produce the step's result.
 * Routing which fallback applies is a domain guard `fn(vars): bool`, never the failed activity's exception
 * class, since infra is not domain.
 *
 * @see Activity::run()
 * @see FallbackCandidate
 */
interface FallbackStrategy
{
    /**
     * Runs the fallback for the given vars and reports its result.
     *
     * @param  array<string, mixed>  $vars  the state's vars at the moment of failure
     *
     * @throws Throwable any failure of the fallback; the engine catches it and moves on to the next
     *                   candidate, else the `Failure` transition
     */
    public function execute(array $vars, Metadata $metadata): ActivityResult;
}
