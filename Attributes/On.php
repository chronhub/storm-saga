<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use ValueError;

/**
 * A transition rule: from state `$from`, when `$trigger` fires and the optional `$guard` method returns
 * true, go to state `$to`. `$guard` names a method on the workflow class, `fn(array $vars): bool`, bound
 * at build time.
 *
 * `$onEvent` is only meaningful for the `event` trigger and routes a wait state by which event class
 * arrived: take this transition when an event of that class is delivered. So one wait can resolve to
 * different targets:
 *
 * ```php
 * #[On(from: 'await', trigger: 'event', onEvent: Paid::class, to: 'ship')]
 * #[On(from: 'await', trigger: 'event', onEvent: Declined::class, to: 'cancel')]
 * ```
 *
 * An `event` transition with no `$onEvent` is the catch-all for any matched event; an exact `$onEvent`
 * match wins over it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class On
{
    public OnTrigger $trigger;

    /**
     * @param  string|OnTrigger  $trigger  'success' | 'failure' | 'timeout' | 'event' | 'schedule'
     * @param  class-string|null  $onEvent  for the `event` trigger: the event class that selects this transition
     *
     * @throws ValueError when `$trigger` is a string that is not a valid OnTrigger
     */
    public function __construct(
        public string $from,
        string|OnTrigger $trigger,
        public string $to,
        public ?string $guard = null,
        public ?string $onEvent = null,
    ) {
        $this->trigger = is_string($trigger) ? OnTrigger::from($trigger) : $trigger;
    }
}
