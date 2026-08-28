<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use LogicException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Effects;
use Storm\Saga\Engine\Outcome\Nothing;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Plan\AdvanceInstance;
use Storm\Saga\Engine\Plan\ApplyUserSignal;
use Storm\Saga\Engine\Plan\CancelInstance;
use Storm\Saga\Engine\Plan\EnforceGlobalDeadline;
use Storm\Saga\Engine\Plan\EscalateWait;
use Storm\Saga\Engine\Plan\HaltAtGlobalCap;
use Storm\Saga\Engine\Plan\SettleFailedEffect;
use Storm\Saga\Engine\Plan\Skip;
use Storm\Saga\Engine\Plan\SkipReason;
use Storm\Saga\Engine\Plan\StartInstance;
use Storm\Saga\Engine\Plan\StepPlan;
use Storm\Saga\Engine\Plan\WaiveGlobalCap;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Event\SagaFamilyCrossingReplayed;
use Storm\Saga\Event\SagaRetimed;
use Storm\Saga\Event\SagaRetimeDenied;
use Storm\Saga\Event\SagaStarted;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\Retime;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * The step's perform phase: map the policy's plan to an outcome through the machine and the named
 * performers, everything returned as data for the committer to write. The gates and settlers ride
 * here by design: a join arm rests in place before the machine runs, a family's conclusion waits
 * for its members, and a crossing disposes of a race's losers or a join's ledger in the same
 * outcome it produces.
 */
final readonly class StepPerformer
{
    public function __construct(
        private MachineRunner $machine,
        private Compensator $compensator,
        private WaitEscalator $escalator,
        private DeadlineEnforcer $enforcer,
        private FailedEffectSettler $settler,
        private Canceller $canceller,
        private JoinSettler $joins,
        private RaceSettler $races,
        private FamilyGate $families,
    ) {}

    /**
     * @param  StartInstance|AdvanceInstance|EscalateWait|EnforceGlobalDeadline|HaltAtGlobalCap|WaiveGlobalCap|SettleFailedEffect|CancelInstance|ApplyUserSignal|Skip  $plan  the policy's sealed union; the no-default match below is proven exhaustive against it
     *
     * @throws WorkflowStepLimitExceeded when a synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws Throwable when the machine throws an exception, re-thrown
     */
    public function perform(StepPlan $plan, WorkflowDefinition $def, WorkflowId $id, ?WorkflowInstanceRow $row, PointInTime $now, ?string $causationId): Created|Updated|Effects|Nothing
    {
        if ($plan instanceof Skip) {
            return new Nothing($plan->reason);
        }
        if ($plan instanceof StartInstance) {
            return $this->performStart($def, $id, $plan, $now, $causationId);
        }

        /** @var WorkflowInstanceRow $row every remaining plan operates on a loaded row, the policy's contract */
        return match (true) {
            $plan instanceof AdvanceInstance => $this->performAdvance($def, $row, $plan->stimulus, $now, $causationId),
            $plan instanceof EscalateWait => $this->escalator->escalate($def, $row),
            $plan instanceof EnforceGlobalDeadline => $this->updated($this->enforcer->enforce($def, $row, $now, $causationId)),
            $plan instanceof HaltAtGlobalCap => $this->updated($this->enforcer->haltAtCap($row)),
            $plan instanceof WaiveGlobalCap => $this->updated($this->enforcer->waiveAtCap($row, $now)),
            $plan instanceof SettleFailedEffect => $this->updated($this->settler->settle($def, $row, $causationId)),
            $plan instanceof CancelInstance => $this->updated($this->canceller->cancel($def, $row, $plan->reason, $causationId)),
            $plan instanceof ApplyUserSignal => $this->performUserSignal($def, $row, $plan),
        };
    }

    /**
     * Apply a user signal to the live row: invoke the declared handler, which the policy guaranteed
     * exists, with the signal object and the current vars; persist the returned bag at the same state
     * with no transition and no timer churn, the event/signal split, and issue its commands from the
     * current state, all atomic with the OCC version bump.
     *
     * The one sanctioned exception to "no timer churn" is a returned `Retime`: where the resting wait
     * declared `#[Retimable]` and its caps hold, the deadline upsert rides this same step; otherwise
     * the retime is observably DENIED, announced with its reason, while the vars and commands still
     * land, since a silent drop would let the caller believe a deadline moved that did not.
     */
    private function performUserSignal(WorkflowDefinition $def, WorkflowInstanceRow $row, ApplyUserSignal $plan): Updated
    {
        $handler = $def->signalHandlerFor($plan->signal)
            ?? throw new LogicException('ApplyUserSignal without a handler — the policy must have routed this; definition and policy disagree.');

        $result = $handler($plan->signal, $row->vars);

        $resting = $row->restingAt($row->stateKey, $row->status, $result->vars, $row->retries, $row->compensations);
        $commands = array_map(static fn (object $c): IssuedCommand => new IssuedCommand($row->stateKey, $c), $result->commands);

        if ($result->retime === null) {
            return new Updated($resting, [], $commands, signalReply: $result->result);
        }

        return $this->retimed($def, $resting, $result->retime, $commands, $result->result);
    }

    /**
     * Judge and apply a signal's retime against the resting state's declared grant. Three refusals,
     * each announced: the resting state carries no grant, since the signal may land in ANY state so
     * an ill-timed retime is runtime weather, not a build error; the instance spent its
     * `maxRetimes`; or the single move reaches past `maxExtensionSeconds`. A granted retime re-arms
     * the wait's deadline relative to now, the timer upsert replacing the armed instant, and bumps
     * the durable retime counter in the same write. The workflow's GLOBAL deadline timer is never
     * touched: a retimed wait can sit past the cap, and the cap still fires.
     *
     * A `restart` of a business-time deadline re-arms through the calendar and is not judged against
     * `maxExtensionSeconds`, since its reach is the declared calendar window, not a requested one.
     *
     * @param  list<IssuedCommand>  $commands
     */
    private function retimed(WorkflowDefinition $def, WorkflowInstanceRow $resting, Retime $retime, array $commands, ?object $reply = null): Updated
    {
        $state = $def->state($resting->stateKey);
        $policy = $state instanceof WaitState ? $state->retime : null;

        if ($policy === null) {
            return new Updated($resting, [], $commands, [$this->retimeDenied($resting, 'not_retimable_here')], $reply);
        }
        if ($policy->maxRetimes !== null && $resting->retimes >= $policy->maxRetimes) {
            return new Updated($resting, [], $commands, [$this->retimeDenied($resting, 'budget_exhausted')], $reply);
        }

        /** @var Timeout $timeout the build proved a retimable wait carries its own deadline */
        $timeout = $state->timeout;
        $seconds = $retime->seconds ?? ($timeout->isBusiness() ? null : $timeout->seconds);

        if ($seconds !== null && $policy->maxExtensionSeconds !== null && $seconds > $policy->maxExtensionSeconds) {
            return new Updated($resting, [], $commands, [$this->retimeDenied($resting, 'beyond_extension_cap')], $reply);
        }

        $op = $seconds !== null
            ? TimerOp::armTimeout($resting->stateKey, $seconds)
            : TimerOp::armBusinessTimeout($resting->stateKey, $timeout->businessDays, $timeout->businessHours);

        $counted = $resting->retimed();

        return new Updated($counted, [$op], $commands, [
            new SagaRetimed($counted->workflowType, $counted->correlationId, $counted->generation, $counted->stateKey, $seconds ?? 0, $counted->retimes),
        ], $reply);
    }

    private function retimeDenied(WorkflowInstanceRow $row, string $reason): SagaRetimeDenied
    {
        return new SagaRetimeDenied($row->workflowType, $row->correlationId, $row->generation, $row->stateKey, $reason);
    }

    /**
     * Create the fresh row at the start state and drive it; arm the overall deadline if the saga is
     * still running after the start drive. A start on a bare untimed wait rests on the undriven row.
     *
     * @throws WorkflowStepLimitExceeded when the start's synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws Throwable when the start throws an exception, re-thrown
     */
    private function performStart(WorkflowDefinition $def, WorkflowId $id, StartInstance $plan, PointInTime $now, ?string $causationId): Created
    {
        $fresh = WorkflowInstanceRow::fresh($id, $def->start, $plan->vars, $plan->context, $now, $def->version, $def->stateVersion);
        $started = new SagaStarted($id->workflowType, $id->correlationId, $fresh->generation, $def->start);

        // the declared birth delay: the saga is BORN in full, row, claim, fence, dedup and the run
        // identity untouched, but the first drive is DEFERRED as a kick that re-runs the start
        // state with no stimulus, exactly the drive a birth performs. The global deadline still
        // anchors HERE and includes the delay; a kick landing past the cap enforces instead of
        // driving, and the build already refused a delay the cap could not survive.
        if ($def->startAfterSeconds !== null) {
            $ops = [TimerOp::armKick($def->start, $def->startAfterSeconds * 1000)];
            if ($def->globalTimeout !== null) {
                $ops[] = TimerOp::armGlobal($def->globalTimeout);
            }

            return new Created($fresh, $ops, [], [$started]);
        }

        $run = $this->machine->run($def, $fresh, Stimulus::none(), $now, $causationId);

        $resting = $fresh;
        $ops = [];
        $commands = [];
        $announcements = [$started];

        if ($run instanceof Rested) {
            $run = $this->compensator->maybeCompensate($def, $run, $causationId);
            $resting = $run->row;
            $ops = $run->timerOps;
            $commands = $run->commands;
            $announcements = [$started, ...$run->announcements];
        }

        if ($resting->status === WorkflowStatus::Running && $def->globalTimeout !== null) {
            $ops = [...$ops, TimerOp::armGlobal($def->globalTimeout)];
        }

        return new Created($resting, $ops, $commands, $announcements);
    }

    /**
     * Run the machine from the stimulus; an unmatched event moves nothing.
     *
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws Throwable when the machine throws an exception, re-thrown
     */
    private function performAdvance(WorkflowDefinition $def, WorkflowInstanceRow $row, Stimulus $stimulus, PointInTime $now, ?string $causationId): Updated|Nothing
    {
        if ($stimulus->replayedEventClassOrNull() !== null) {
            return $this->performReplay($def, $row, $stimulus, $now, $causationId);
        }

        // a join arm's completion that is NOT the last must not cross the joining wait: it rests in
        // place, marked and logged, before the machine ever runs; a duplicate arrival is absorbed
        $gate = $this->joins->gateArrival($def, $row, $stimulus);
        if ($gate instanceof Rested) {
            return $this->updated($gate); // nothing crossed, so no compensation pass to take
        }
        if ($gate instanceof Unmoved) {
            return new Nothing;
        }

        // a conclusion landing on an indexed family's awaited wait must not cross while members
        // are still out: the counts are read from truth and the saga rests in place, vars landed,
        // and the crossing it owes back parked against the wait
        $family = $this->families->gateConclusion($def, $row, $stimulus, $causationId);
        if ($family instanceof Rested) {
            return $this->updated($family);
        }

        $run = $this->machine->run($def, $row, $stimulus, $now, $causationId);
        if (! $run instanceof Rested) {
            return new Nothing; // nothing happened, an unmatched event
        }

        // a delivered outcome that crossed a race's settling wait disposes of the losers IN THIS
        // step: recall what never dispatched, undo what did, and log the winner confirmed
        $run = $this->races->settleIfWon($def, $row, $stimulus, $run, $causationId);
        // a crossing out of a JOINING wait is accounted for the same way: the last completion joins
        // the ledger confirmed, a definitive arm failure disposes of the whole join
        $run = $this->joins->settle($def, $row, $stimulus, $run, $causationId);

        return $this->updated($this->compensator->maybeCompensate($def, $run, $causationId)); // halt-with-rollback, so compensate
    }

    /**
     * Spend the crossing an indexed family's gate rested: the completeness is re-judged from truth
     * FIRST, since a poke rides at-least-once and any member's settle sends one, so most of them
     * arrive while siblings still run. Then the park is discharged and the machine crosses, the
     * announcement naming the replay beside whatever the crossing itself announces.
     *
     * The crossing runs under the ABSORBED CONCLUSION's causation, not the poke's. The poke is the
     * mechanism that woke the saga; the conclusion is what actually caused the crossing, and the
     * operator's correlation trace reads that chain, so naming the mechanism there would hide the
     * business fact behind the plumbing that revealed it. A park taken without a causation falls
     * back to the poke's own, which is better than nothing and honest about what it is.
     *
     * The race and join settlers are deliberately not consulted here. Their evidence is the arm an
     * outcome belongs to, and the build proves a wait cannot be both an arm's settling wait and a
     * family's awaited wait: a race's settling wait accepts EXACTLY its arms' outcomes and refuses a
     * foreign accepted event, and the join rule is its dual. A compensation pass still runs, a
     * replayed crossing being able to land on a halting state like any other.
     *
     * @throws WorkflowStepLimitExceeded when the synchronous transition chain cycles
     * @throws UnknownState when a transition targets an undeclared state
     * @throws MissingAsyncTimeout when an async activity state declares no timeout
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws SagaStorageFailure when the member counts cannot be read; the step rolls back whole
     * @throws Throwable when the machine throws an exception, re-thrown
     */
    private function performReplay(WorkflowDefinition $def, WorkflowInstanceRow $row, Stimulus $stimulus, PointInTime $now, ?string $causationId): Updated|Nothing
    {
        $parked = $row->parked
            ?? throw new LogicException('A replayed crossing on a row that parked nothing — the policy must have routed this; policy and performer disagree.');

        if (! $this->families->readyToCross($def, $row)) {
            return new Nothing(SkipReason::FamilyIncomplete);
        }

        $cause = $parked['cause'] ?? $causationId;

        // discharged BEFORE the machine runs: a crossing that lands back on this same wait, a
        // self-loop, must not leave the next poke a second claim on it
        $run = $this->machine->run($def, $row->unparked(), $stimulus, $now, $cause);
        if (! $run instanceof Rested) {
            return new Nothing; // the pinned definition routes this class nowhere any more
        }

        /** @var class-string $eventClass the replay branch is entered on a non-null class */
        $eventClass = $stimulus->replayedEventClassOrNull();
        $run = new Rested(
            $run->row,
            [new SagaFamilyCrossingReplayed($row->workflowType, $row->correlationId, $row->generation, $row->stateKey, $eventClass), ...$run->announcements],
            $run->timerOps,
            $run->commands,
        );

        return $this->updated($this->compensator->maybeCompensate($def, $run, $cause));
    }

    private function updated(Rested $run): Updated
    {
        return new Updated($run->row, $run->timerOps, $run->commands, $run->announcements);
    }
}
