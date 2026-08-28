<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\Retimable;

/**
 * The declared right of one wait state to have its armed deadline moved by a signal, and the caps
 * that keep that right from dissolving the deadline: `WaitRunner` deliberately refuses to let
 * correlated chatter push a business deadline, so opening a retime transfers the anti-starvation
 * risk to the application, and the build refuses the declaration unless at least one cap bounds it.
 *
 * `maxRetimes` caps how many retimes ONE VISIT of the wait may absorb, the `maxAttempts` twin,
 * counted durably on the row and reset when the saga comes to rest on a new state, so a recurring
 * saga renegotiates each window afresh. `maxExtensionSeconds` caps a single retime's reach from
 * now; a `Retime::restart()` re-arms the declared deadline and is judged against the same cap.
 *
 * @see Retimable
 * @see Retime
 */
final readonly class RetimePolicy
{
    /**
     * @param  int|null  $maxRetimes  per-visit cap on applied retimes; null leaves the count unbounded
     * @param  int|null  $maxExtensionSeconds  cap on a single retime's reach from now; null leaves it unbounded
     */
    public function __construct(
        public ?int $maxRetimes = null,
        public ?int $maxExtensionSeconds = null,
    ) {}
}
