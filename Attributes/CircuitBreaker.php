<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Protects an activity state with a circuit breaker. After `$failureThreshold` consecutive failures the
 * breaker opens and further runs fast-fail straight to the `failure` transition with no retry, since
 * re-hitting a downed resource is futile, until `$cooldownSeconds` elapse; then a single probe is
 * admitted, success closing it and failure re-opening it.
 *
 * `$resource` is mandatory and explicit on purpose: it names the resource the breaker guards, such as
 * `payment-gateway`, so every activity hitting that resource across states, sagas, and workers shares one
 * breaker. A per-class default would split the same service into incoherent separate breakers, so the dev
 * must choose the granularity.
 *
 * `$resourceKey` opts into a per-instance breaker: when set, it names a `vars` key whose value is folded
 * into the resource as `{resource}:{vars[resourceKey]}`, so a fleet of independent fallible resources each
 * breaks on its own; one breaker per device means a jammed instance does not fast-fail requests at healthy
 * ones. This mirrors the keyed-breaker-registry idiom of resilience4j or Polly; leave it null for the
 * shared, fleet-wide breaker. The key space must stay bounded per device, tenant, or partition, never an
 * unbounded per-request id, since each distinct key is a durable breaker row.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class CircuitBreaker
{
    public function __construct(
        public string $state,
        public string $resource,
        public int $failureThreshold = 5,
        public int $cooldownSeconds = 30,
        public ?string $resourceKey = null,
    ) {}
}
