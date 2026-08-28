<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Declares a user-signal handler on the workflow: when `Engine::signal()` delivers an instance of
 * `$signal` to a running saga, the engine invokes `$handler`, bound to the workflow instance, with the
 * signal object and the current vars. A signal is the event's instruction-side twin: it updates the vars
 * and may issue commands, but it resolves no wait and drives no transition, so the saga stays where it
 * rests; this is the event-signal split. Keyed by the signal's exact class; repeatable, one handler per
 * signal class. A signal no handler claims is dropped observably; nothing is buffered.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Signal
{
    /**
     * @param  string  $signal  the signal's concrete class; the handler lookup is exact-class
     * @param  string  $handler  the workflow method to bind, `(object $signal, array $vars): SignalResult`;
     *                           the first parameter may be typed with the signal class itself, since the
     *                           engine never passes anything else
     */
    public function __construct(
        public string $signal,
        public string $handler,
    ) {}
}
