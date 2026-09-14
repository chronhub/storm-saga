<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use RuntimeException;
use Throwable;

/**
 * A drain stopped by a transient dispatch failure, thrown AFTER the progress made so far was
 * committed: commands published, dead-letters, their settles and the failed row's bumped back-off are
 * all durable.
 *
 * The relay "fails forward": signaling the outage must not hide the work that did commit, so this
 * carries it. Its reason for existing is the scheduler's: without it the console reported a clean run
 * under an unreachable broker, so nothing outside the log said the command lane had stopped. The
 * first transient failure is the cause; the withheld commands stay `pending` for the next tick.
 *
 * @see SagaOutboxRelay::drain()
 */
final class SagaOutboxDrainIncomplete extends RuntimeException
{
    private function __construct(
        public readonly SagaOutboxDrainResult $progress,
        Throwable $cause,
    ) {
        parent::__construct(
            sprintf(
                'Saga command outbox drain stopped by a transient publish failure after relaying %d command(s) (%d dead-lettered): %s',
                $progress->published,
                $progress->failed,
                $cause->getMessage(),
            ),
            previous: $cause,
        );
    }

    public static function after(SagaOutboxDrainResult $progress, Throwable $cause): self
    {
        return new self($progress, $cause);
    }
}
