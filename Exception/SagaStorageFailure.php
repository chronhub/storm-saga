<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Throwable;

/**
 * The saga storage failed: the port-owned failure every storage adapter raises in place of its
 * driver's exceptions, so the engine's contract names THIS class, never the driver's, and swapping
 * the adapter never changes what callers catch. A translated failure, such as a DBAL error, a JSON
 * codec failure, or an unparseable stored instant, chains the original as `previous`; a refusal of
 * stored data the writes could never have produced has no original to chain.
 */
final class SagaStorageFailure extends RuntimeException implements SagaException
{
    public static function unavailable(Throwable $cause): self
    {
        return new self('The saga storage failed: '.$cause->getMessage(), previous: $cause);
    }

    public static function corrupted(string $detail): self
    {
        return new self('The saga storage returned corrupt data: '.$detail);
    }
}
