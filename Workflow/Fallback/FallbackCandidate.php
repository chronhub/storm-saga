<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Fallback;

use Closure;

/**
 * One link in a state's fallback chain: a `FallbackStrategy` plus an optional domain guard
 * `fn(vars): bool`. The guard decides whether this fallback even applies, such as using the cached price
 * only during business hours, reading workflow vars, never an infra exception class. No guard means it
 * always applies.
 */
final readonly class FallbackCandidate
{
    public function __construct(
        public FallbackStrategy $strategy,
        /** @var (Closure(array<string, mixed>): bool)|null */
        public ?Closure $guard = null,
    ) {}

    /**
     * @param  array<string, mixed>  $vars
     */
    public function applies(array $vars): bool
    {
        return $this->guard === null || ($this->guard)($vars);
    }
}
