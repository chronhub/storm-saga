<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Closure;
use Storm\Saga\Attributes\OnTrigger;

/**
 * One edge out of a state: on `$trigger`, go to `$to`, provided the optional `$guard`
 * `fn(array $vars): bool`, bound to the workflow instance at build time, returns true.
 *
 * `$onEvent`, meaningful only for the event trigger, scopes the edge to a specific event class: it fires
 * only when an event of that class is the one that matched the wait. Null is a catch-all for any matched
 * event. An exact `$onEvent` match is preferred over the catch-all.
 */
final readonly class Transition
{
    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $guard
     * @param  class-string|null  $onEvent
     */
    public function __construct(
        public OnTrigger $trigger,
        public string $to,
        public ?Closure $guard = null,
        public ?string $onEvent = null,
    ) {}
}
