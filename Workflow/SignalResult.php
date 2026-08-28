<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * A user-signal handler's outcome, the mirror of {@see ActivityResult} without a transition: the saga
 * stays at its state; only the vars move and commands may be issued, written to the saga outbox atomically
 * with the version bump. A signal that wants to transition is an event; use a WaitFor, not a signal, the
 * event-signal split.
 *
 * An optional `$retime` moves the resting wait's armed deadline in the same step, without leaving the
 * state: the one timer a signal may touch, and only where `#[Retimable]` declared the right. The
 * engine judges it at application: an unearned or over-budget retime is observably denied while the
 * vars and commands still land.
 *
 * An optional `$result` is the handler's typed reply, handed back to a `signalFor()` caller once the
 * step has written: the durable mutation and the answer come from ONE handler run under the fence,
 * instead of a signal-then-reread racing its own gap. It rides the step, so it is never observable
 * from a step that rolled back, and it is worth exactly what the caller's transaction is worth: under
 * an ambient DBAL transaction the step is a savepoint, and the reply exists before the outer commit
 * makes it durable, the same window the engine's announcements already tolerate. The plain `signal()`
 * entry ignores it.
 */
final readonly class SignalResult
{
    /**
     * @param  array<string, mixed>  $vars  the full vars bag to persist; the handler returns the merged state
     * @param  list<object>  $commands  commands to issue from the current state
     * @param  Retime|null  $retime  move the resting wait's deadline; null touches no timer
     * @param  object|null  $result  the typed reply a `signalFor()` caller receives; null answers nothing
     */
    private function __construct(
        public array $vars,
        public array $commands = [],
        public ?Retime $retime = null,
        public ?object $result = null,
    ) {}

    /**
     * @param  array<string, mixed>  $vars
     * @param  list<object>  $commands
     */
    public static function stay(array $vars, array $commands = [], ?Retime $retime = null, ?object $result = null): self
    {
        return new self($vars, $commands, $retime, $result);
    }
}
