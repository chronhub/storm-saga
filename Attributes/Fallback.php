<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Declares a fallback for an activity `$state`, run when the activity fails after retries or its circuit
 * breaker is open, to degrade gracefully instead of taking the `Failure` transition. Each attribute sets
 * exactly one of `$activity`, an alternative activity to run, or `$vars`, default values to succeed with.
 * Optional `$guard` names a domain method `fn(vars): bool` deciding whether this fallback applies, never
 * the failed activity's exception class, since infra is not domain.
 *
 * Repeatable: several `#[Fallback]` on one state form a chain, tried in declaration order until one
 * succeeds, such as a secondary provider then a cached default.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Fallback
{
    /**
     * @param  class-string|null  $activity  an alternative activity to run
     * @param  array<string, mixed>|null  $vars  static default vars to succeed with
     */
    public function __construct(
        public string $state,
        public ?string $activity = null,
        public ?array $vars = null,
        public ?string $guard = null,
    ) {}
}
