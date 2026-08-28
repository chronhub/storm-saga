<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Workflow\WaitState;

/**
 * Applies a wait's declared `extract` to a matched event, writing named keys into `vars` so an
 * activity reads a named var, not a schemaless bag. Either a bound method, the matcher's data-side
 * twin that reads the typed event and may transform, or a `varName => payloadField` map that reads
 * the wire payload's top-level fields; declared keys win over existing vars, explicit beats
 * implicit. A map-declared field absent from the payload is a wiring bug and throws, never a silent
 * null.
 *
 * Its own unit because TWO callers land vars identically: the `WaitRunner` when a matched event
 * crosses the wait, and the `JoinSettler` when a partial completion rests in place. A join arm's
 * vars must land exactly as the crossing would land them, or the arrival order would change what
 * the activities read.
 *
 * @see WaitRunner
 * @see JoinSettler
 */
final readonly class WaitVarExtractor
{
    public function __construct(
        private EventResolver $events,
    ) {}

    /**
     * Whether the wait accepts `$event`: by class, by alias, then the optional matcher. The
     * runner's own test, shared for the same reason as `apply()`: a gate that rests an event in
     * place must accept exactly what the crossing would have accepted.
     *
     * @param  array<string, mixed>  $vars
     */
    public function matches(WaitState $state, object $event, array $vars): bool
    {
        $matched = array_any($state->eventClasses, static fn (string $class): bool => $event instanceof $class);
        if (! $matched && $state->eventTypes !== []) {
            $alias = $this->events->aliasFor($event);
            $matched = $alias !== null && in_array($alias, $state->eventTypes, true);
        }

        if ($matched && $state->matcher !== null) {
            $matched = ($state->matcher)($event, $vars);
        }

        return $matched;
    }

    /**
     * @param  array<string, mixed>  $vars
     * @return array<string, mixed>
     *
     * @throws MissingExtractField when a map-declared field is absent from the matched event's payload
     */
    public function apply(WaitState $state, object $event, array $vars): array
    {
        if ($state->extract !== null) {
            return [...$vars, ...($state->extract)($event, $vars)];
        }

        if ($state->extractMap === []) {
            // @infection-ignore-all; equivalent: falls through to the identical `return $vars` below, since the foreach over an empty map is a no-op
            return $vars;
        }

        $payload = $this->events->payloadFor($event);
        foreach ($state->extractMap as $target => $field) {
            if (! array_key_exists($field, $payload)) {
                throw MissingExtractField::inWait($field, $state->key);
            }
            $vars[$target] = $payload[$field];
        }

        return $vars;
    }
}
