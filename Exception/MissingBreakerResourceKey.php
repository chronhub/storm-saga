<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * A `#[CircuitBreaker]` declares a `resourceKey` for a per-instance breaker, but the saga's `vars` carry
 * no scalar value under that key when the guarded state runs. Caught at runtime, since we can't know
 * statically that the vars will hold the key, but it is an authoring bug: a per-instance breaker keys on
 * a value the saga must have set before the state. The miss is failed loud, never collapsed to the
 * shared resource: a silent fall-back would lump every instance into one fleet-wide breaker, the exact
 * opposite of what `resourceKey` asks for.
 */
final class MissingBreakerResourceKey extends LogicException implements SagaException
{
    public static function for(string $resource, string $resourceKey): self
    {
        return new self(sprintf(
            'Circuit breaker for resource "%s" declares resourceKey "%s", but the saga vars hold no scalar '
            .'value under that key at this state. A per-instance breaker must find its key in vars; the miss '
            .'is never silently collapsed to the shared resource.',
            $resource,
            $resourceKey,
        ));
    }
}
