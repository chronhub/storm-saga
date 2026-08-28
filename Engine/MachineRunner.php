<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Run\MachineRun;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * The drive loop, pure: run the current state's runner, cross-transitions, until the machine rests,
 * across two branches and one immutable cursor. There is no IO, since timer cancels are collected as
 * ordered ops, and no clock, since `now` is a value; the loop physically cannot write, which is what
 * makes "one step is one transaction" structural rather than a docblock promise, and makes the loop
 * unit- and mutation-testable.
 *
 * Bound: at most `state_count + 2` hops per run, since the throw fires past `state_count + 1`, an
 * affordable backstop against a transition cycle that caps the iteration count rather than doing
 * true cycle detection. A workflow that loops within one synchronous run must keep its chain at most
 * the state count before it rests on Stay, Wait, or Async; most rest within a couple of hops.
 * Cross-rest cycles, a graph that revisits a state via waits, are legal: each lap is its own run,
 * each visit gets a fresh retry budget, and the cursor resets the left state's counter on every
 * crossing.
 *
 * @see MachineRun
 */
final readonly class MachineRunner
{
    public function __construct(
        private ActivityRunner $activity,
        private WaitRunner $wait,
        private ScheduleRunner $schedule,
        private FinalRunner $final,
        private Compensator $compensator,
    ) {}

    /**
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain exceeds the state count,
     *                                   a transition cycle, so it fails fast rather than looping forever
     * @throws UnknownState when a transition targets a state the definition doesn't declare
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws MissingExtractField when a map-declared extract field is absent from a matched event
     * @throws ClockExceptionContract when a breaker cooldown instant cannot be derived
     * @throws Throwable when any other exception is thrown by the state's runner
     */
    public function run(WorkflowDefinition $def, WorkflowInstanceRow $row, Stimulus $stimulus, PointInTime $now, ?string $causationId): Rested|Unmoved
    {
        $cursor = MachineCursor::at($row);
        $limit = count($def->states()) + 1;

        for ($hop = 0; ; $hop++, $stimulus = Stimulus::none()) { // the trigger applies to the first hop only
            if ($hop > $limit) {
                throw WorkflowStepLimitExceeded::inWorkflow($row->workflowType, $limit);
            }

            /** @var ActivityState|WaitState|ScheduleState|FinalState $state State is sealed to these four; the builder constructs no other */
            $state = $def->state($cursor->stateKey);
            // started_at is NOT NULL in the schema; the ?? only guards the type-nullable unpersisted-row edge
            $ctx = new StateContext($row->workflowType, $row->correlationId, $def, $state, $stimulus, $now, $row->startedAt ?? $now, $cursor->vars, $cursor->retries, $row->context, $causationId, $row->retryTotal);

            $verdict = match (true) {
                $state instanceof ActivityState => $this->activity->run($ctx),
                $state instanceof WaitState => $this->wait->run($ctx),
                $state instanceof ScheduleState => $this->schedule->run($ctx),
                $state instanceof FinalState => $this->final->run($ctx),
            };

            if ($verdict instanceof Transition) {
                $log = $this->compensator->advanceLog($def, $state, $verdict, $stimulus->eventClassOrNull(), $cursor->log, $now);
                $cursor = $cursor->transition($verdict, $log, $this->leftStateMayHaveArmedTimer($state, $hop));

                continue;
            }

            return $cursor->rest($verdict);
        }
    }

    /**
     * Whether a transition off `$state` owes a `cancelState` DELETE, true only when the state could
     * hold an armed timer. A timer is armed at rest, and a saga rests on exactly one state between
     * runs, so only the run's rested state at hop 0 can carry one; a synchronous intermediate past
     * hop 0 transitioned without ever resting, so its cancel would be a no-op. At hop 0, a wait with
     * no timeout armed nothing either. Conservative on a hop-0 activity, which may have rested async
     * or on a retry kick: it never skips a necessary cancel, since the stale-state guard backstops
     * any residual.
     */
    private function leftStateMayHaveArmedTimer(ActivityState|WaitState|ScheduleState|FinalState $state, int $hop): bool
    {
        if ($hop !== 0) {
            return false;
        }

        return $state instanceof ActivityState
            || $state instanceof ScheduleState
            || ($state instanceof WaitState && $state->timeout !== null);
    }
}
