<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

use Storm\Saga\Engine\TimerOp;

/**
 * Rest in the same state: a retry waiting for its back-off kick, an async effect waiting for its
 * outcome, or a wait re-arming its timeout. Carries the authoritative new `$vars`, the per-state
 * attempt counters when a retry consumed budget, the relative timer instructions to arm, and any
 * commands issued, since an async activity issues durably then rests.
 */
final readonly class Stay implements Verdict
{
    /**
     * @param  list<TimerOp>  $timerOps
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{n: int, since: string|null}>|null  $retries  per-state visit ledgers; null means unchanged
     * @param  list<object>  $commands
     * @param  int|null  $retryTotal  the instance's new lifetime retry total, set only by a retry; null means unchanged
     */
    public function __construct(
        public array $timerOps = [],
        public array $vars = [],
        public ?array $retries = null,
        public array $commands = [],
        public ?int $retryTotal = null,
    ) {}
}
