<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Engine\Verdict\Verdict;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The immutable input to a state runner: the instance's identity and current `vars` and `retries`,
 * the state being run, the Stimulus the hop reacts to, a delivered event, a fired timeout, a due
 * schedule slot, or nothing, `now`, the step's single captured instant against which a schedule
 * runner re-arms and counts missed slots, and `anchor`, the instance's birth instant `startedAt` on
 * which a per-instance schedule grid is phased. Always present: a saga's `started_at` is NOT NULL,
 * and the one place the type-nullable row field enters falls back to `now`, so every runner
 * downstream gets a resolved anchor. Pure, with no DB row and no connection: a runner turns a
 * `StateContext` into a Verdict, which is what makes the engine unit-testable without Postgres.
 *
 * @see Verdict
 */
final readonly class StateContext
{
    /**
     * @param  array<string, mixed>  $vars  the instance's state bag
     * @param  array<string, array{n: int, since: string|null}>  $retries  per-state visit ledgers, attempt count
     *                                                                     plus the visit window's opening instant,
     *                                                                     reset per state-visit; a null `since` is
     *                                                                     a pre-window row whose clock starts at
     *                                                                     its next retry
     * @param  array<string, mixed>  $enrichedContext  ambient context handed to activities, such as actor or origin
     * @param  int  $retryTotal  the instance's lifetime retry total, never reset, what the workflow retry budget caps
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public WorkflowDefinition $definition,
        public State $state,
        public Stimulus $stimulus,
        public PointInTime $now,
        public PointInTime $anchor,
        public array $vars = [],
        public array $retries = [],
        public array $enrichedContext = [],
        public ?string $causationId = null,
        // @infection-ignore-all; dead default: the sole constructor, MachineRunner, always passes $row->retryTotal,
        // so the `= 0` is never observed; the Inc/Dec mutants on it are equivalent
        public int $retryTotal = 0,
    ) {}
}
