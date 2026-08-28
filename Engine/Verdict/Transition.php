<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

use Storm\Saga\Attributes\OnTrigger;

/**
 * Take the edge to `$to`, fired by `$trigger`, carrying the authoritative new `$vars` and any
 * commands the state issued. `$degraded` flags a success produced by a fallback, not the primary
 * activity: the step's real effect is uncertain, so a later rollback won't blindly undo it.
 */
final readonly class Transition implements Verdict
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  list<object>  $commands  commands to issue durably, written to the saga outbox in the step tx
     */
    public function __construct(
        public string $to,
        public OnTrigger $trigger,
        public array $vars = [],
        public array $commands = [],
        public bool $degraded = false,
    ) {}
}
