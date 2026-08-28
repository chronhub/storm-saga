<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\Exception\MissingBreakerResourceKey;

/**
 * The resolved circuit-breaker policy on an `ActivityState`, built from `#[CircuitBreaker]`: the shared
 * resource `$key`, the consecutive-failure `$failureThreshold` that trips it, and the `$cooldownSeconds`
 * before a probe is admitted. A pure value object; the runtime behavior lives in `CircuitBreaker`.
 *
 * `$resourceKey`, the attribute's `resourceKey`, opts into a per-instance breaker: `resolve()` folds
 * `vars[resourceKey]` into the key at run time, so a fleet of resources each breaks independently. Null
 * means the shared, fleet-wide breaker.
 *
 * @see ActivityState
 * @see CircuitBreaker
 */
final readonly class CircuitBreakerPolicy
{
    public function __construct(
        public string $key,
        public int $failureThreshold,
        public int $cooldownSeconds,
        public ?string $resourceKey = null,
    ) {}

    /**
     * The policy keyed for a run's `$vars`: itself when `$resourceKey` is null, the shared breaker, else a
     * copy whose `key` is `{key}:{vars[resourceKey]}`, one independent breaker per instance. The vars value
     * must be present and scalar; a missing or non-scalar value is a loud authoring error, never a silent
     * fall-back to the shared key that would lump every instance into one breaker.
     *
     * @param  array<string, mixed>  $vars
     *
     * @throws MissingBreakerResourceKey when `$resourceKey` is set but `vars[resourceKey]` is absent / not scalar
     */
    public function resolve(array $vars): self
    {
        if ($this->resourceKey === null) {
            return $this;
        }

        $value = $vars[$this->resourceKey] ?? null;
        if (! is_scalar($value)) {
            throw MissingBreakerResourceKey::for($this->key, $this->resourceKey);
        }

        return new self($this->key.':'.$value, $this->failureThreshold, $this->cooldownSeconds);
    }
}
