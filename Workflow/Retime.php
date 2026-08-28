<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\Retimable;
use ValueError;

/**
 * A signal handler's instruction to move the deadline of the wait the saga is RESTING on, without
 * leaving the state: the retime rides the signal's step, and the timer upsert replaces the armed
 * instant in the same transaction as the vars and the OCC bump.
 *
 * Both forms are relative to NOW, never to the previously armed instant: `extend($seconds)` re-arms
 * the deadline `$seconds` from now, the negotiated-extension form; `restart()` re-arms the state's
 * own declared deadline from now, the debounce form. Anchoring on now keeps the instruction
 * deterministic under redelivery, since the previous instant is not an input.
 *
 * The right to retime is declared per wait by `#[Retimable]`, capped there, and judged by the engine
 * at application: a retime without the declared right, past its budget, or beyond its per-call cap is
 * observably DENIED, announced with its reason while the signal's vars and commands still land. The
 * workflow's global cap is never touched: a retimed deadline can sit past it, and the global timer
 * still fires.
 *
 * @see Retimable
 * @see SignalResult::stay()
 */
final readonly class Retime
{
    /**
     * @param  int|null  $seconds  seconds from now; null re-arms the wait's own declared deadline
     */
    private function __construct(public ?int $seconds) {}

    /**
     * Re-arm the resting wait's deadline `$seconds` from now: "48 more hours from this decision",
     * not "48 hours on top of the old deadline".
     *
     * A non-positive extension is refused HERE, at the handler's own call site: the shell floors an
     * armed timeout to one second, so letting a zero or negative value through would not fail; it
     * would quietly COLLAPSE the deadline to now, an instant expiry wearing an extension's name.
     *
     * @throws ValueError when `$seconds` is not positive
     */
    public static function extend(int $seconds): self
    {
        if ($seconds < 1) {
            throw new ValueError(sprintf('Retime::extend() requires a positive number of seconds, got %d — a non-positive "extension" would collapse the deadline to now.', $seconds));
        }

        return new self($seconds);
    }

    /**
     * Re-arm the resting wait's own declared deadline from now: the debounce form, "the window
     * restarts on each sign of life".
     */
    public static function restart(): self
    {
        return new self(null);
    }
}
