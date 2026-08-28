<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Exception;

use RuntimeException;
use Storm\Saga\Exception\SagaException;

/**
 * The semaphore did not answer: no provisioned instance holds the resource's correlation, or the
 * operator paused or settled it. Thrown by the client instead of returning null, because a silent
 * non-answer would read as "queued" to the caller; loud is the only honest shape when the resource's
 * guardian is absent.
 */
final class SemaphoreUnavailable extends RuntimeException implements SagaException
{
    public static function for(string $resource): self
    {
        return new self(sprintf(
            'Semaphore "%s" did not answer: not provisioned, paused, or settled. Provision it before acquiring.',
            $resource,
        ));
    }
}
