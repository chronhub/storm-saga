<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Throwable;

/**
 * An announcement listener failed after the step's unit of work returned.
 *
 * The step's writes may already be committed; an ambient transaction may still own them.
 * This failure must not consume the failure budget of a timer the step has rearmed.
 */
final class SagaAnnouncementFailed extends RuntimeException implements SagaException
{
    public function __construct(public readonly Throwable $cause)
    {
        parent::__construct('Saga announcement dispatch failed: '.$cause->getMessage(), previous: $cause);
    }
}
