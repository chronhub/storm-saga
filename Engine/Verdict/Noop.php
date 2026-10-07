<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

/**
 * Nothing to do: an event that matched nothing on a wait with no timeout to arm, or an event
 * delivered to a schedule awaiting its timer. The machine rests,
 * or reports nothing happened when no hop advanced.
 */
final readonly class Noop implements Verdict {}
