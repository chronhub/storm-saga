<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * An activity returned an async result, but its state declares no timeout. Caught at runtime, since we
 * can't know statically that an activity will go async, but it is a configuration bug: an async step
 * arms its deadline as a `workflow_timers` row, and a missing one would let the saga wait forever with
 * nothing to rescue it. Express an effectively unbounded wait as a long timeout, never as a missing one.
 */
final class MissingAsyncTimeout extends LogicException implements SagaException
{
    public static function forState(string $stateKey, string $workflowType): self
    {
        return new self(sprintf(
            'Activity state "%s" in workflow "%s" returned an async result but declares no timeout. '
            .'An async activity must arm a deadline (use a long timeout for an unbounded wait).',
            $stateKey,
            $workflowType,
        ));
    }
}
