<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Closure;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\WaitFor;

/**
 * Pauses until a matching event is delivered: it accepts the events named by `$eventClasses` as FQCNs
 * and/or `$eventTypes` as stable aliases, with an optional `$matcher` `fn(object $event, array $vars):
 * bool` for finer matching, then yields the `Event` trigger. Optional `$timeout` arms a wait timer. On a
 * match an optional `$extract` writes named keys into `vars`, either a bound method that is the matcher's
 * data-side twin or, equivalently, a `$extractMap` of `varName => payloadField`; at most one is set and
 * the builder rejects both. `$extractMap` is kept raw so it can be inspected design-time. `$retriable`
 * marks a gating wait whose success is inevitable post-pivot: the engine re-arms it past the instance-wide
 * global cap instead of halting.
 *
 * Optional `$retime` is the `#[Retimable]` grant: a signal may move this wait's armed deadline within
 * the declared caps. Only a wait with its own finalizing deadline may carry it; the build enforces that.
 *
 * @see WaitFor
 * @see Retimable
 */
final class WaitState extends State
{
    /**
     * @param  list<class-string>  $eventClasses
     * @param  list<string>  $eventTypes
     * @param  (Closure(object, array<string, mixed>): bool)|null  $matcher
     * @param  (Closure(object, array<string, mixed>): array<string, mixed>)|null  $extract
     * @param  array<string, string>  $extractMap
     */
    public function __construct(
        string $key,
        public readonly array $eventClasses = [],
        public readonly array $eventTypes = [],
        public readonly ?Closure $matcher = null,
        public readonly ?Timeout $timeout = null,
        public readonly bool $retriable = false,
        public readonly ?Closure $extract = null,
        public readonly array $extractMap = [],
        array $transitions = [],
        public readonly ?RetimePolicy $retime = null,
    ) {
        parent::__construct($key, $transitions);
    }
}
