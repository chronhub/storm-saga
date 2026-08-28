<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore\Exception;

use InvalidArgumentException;
use Storm\Saga\Exception\SagaException;

/**
 * A provisioning value that cannot guard anything: a capacity below one, a negative queue bound, or a
 * TTL that expires before it starts. Refused at the client, before any instance is born, because a
 * semaphore born with a nonsense cap would refuse or grant everything forever, quietly.
 */
final class InvalidSemaphoreProvision extends InvalidArgumentException implements SagaException
{
    public static function capacity(int $capacity): self
    {
        return new self(sprintf('A semaphore needs at least one slot, got capacity %d.', $capacity));
    }

    public static function maxQueue(int $maxQueue): self
    {
        return new self(sprintf('The queue bound cannot be negative, got %d; zero means no queue at all.', $maxQueue));
    }

    public static function ttl(string $which, int $seconds): self
    {
        return new self(sprintf('The %s must be at least one second, got %d.', $which, $seconds));
    }
}
