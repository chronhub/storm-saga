<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * How a `RetryPolicy` spaces its attempts. `Exponential` waits `base * 2^(attempt-1)`; `Fixed` waits
 * `base` every time. The actual delay computation lives with the retry handler; this enum just names the
 * shape, so the policy is type-safe rather than a free-form string.
 *
 * @see RetryPolicy
 */
enum BackoffStrategy: string
{
    case Exponential = 'exponential';

    case Fixed = 'fixed';
}
