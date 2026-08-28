<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Child\PokeParentFamily;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Effects;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Exception\BusinessCalendarMissing;
use Storm\Saga\Exception\ChildrenStillRunning;
use Storm\Saga\Exception\ChildSpawnRefused;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\MalformedJoinFanOut;
use Storm\Saga\Exception\MalformedRaceFanOut;
use Storm\Saga\Exception\ParentNotAdoptable;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\UnattributedJoinCommand;
use Storm\Saga\Exception\UnattributedRaceCommand;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStateRejected;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Throwable;

/**
 * The step's write point: everything an outcome persists, and every invariant that judges it, in
 * the order the guarantees require. A birth proves its adoption and claims its run number before
 * any effect; every write passes the declared state gate; an abort recalls its pending commands
 * and cascades to its living children; a nominal settle refuses to orphan a family; a fan-out
 * proves its shape before its first outbox row; a settling member of an indexed family pokes its
 * parent so an absorbed conclusion can still be spent; and the clock turns relative timer
 * instructions into fire instants exactly once, here. Runs entirely inside the step's unit of work,
 * so any refusal rolls the whole step back.
 */
final readonly class StepCommitter
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private WorkflowInstances $instances,
        private WorkflowFamilies $families,
        private WorkflowTimers $timers,
        private WorkflowOutbox $outbox,
        private Clock $clock,
        private ?BusinessCalendar $calendar = null,
    ) {}

    /**
     * Persist a birth: stamp the fan-out families, prove the adoption, gate the declared state,
     * create the row under the durable correlation claim, and apply the effects. The claim hands
     * back the run number, and it rides onto the row BEFORE the effects are applied: every command
     * this birth issues is sealed to the run that issued it. The returned row carries that
     * generation; the executor stamps it onto the birth-drive announcements, `claimedAs()`'s twin
     * for the trail.
     *
     * @throws ParentNotAdoptable when the declared parent is gone, terminal, or another workflow type
     * @throws ChildSpawnRefused when the parent never declared this slot, or the ceiling is reached
     * @throws InvalidChildIdentity when the row's own parent declaration is malformed
     * @throws WorkflowNotFound when no version of the parent's workflow type is registered
     * @throws WorkflowVersionNotFound when the parent's pinned version was purged while it still runs
     * @throws WorkflowStateRejected when the declared validator refuses the bag
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable the tail of the correlation claim and the effects, re-thrown
     */
    public function created(WorkflowRegistry $registry, WorkflowDefinition $def, WorkflowId $id, Created $outcome, Signal $signal): WorkflowInstanceRow
    {
        $stamped = $this->familiesStamped($def, $outcome->row, $outcome->commands);
        $this->proveAdoption($registry, $stamped);
        $this->guardDeclaredState($def, $stamped);
        $born = $stamped->claimedAs($this->instances->create($stamped, $def->reuse));
        $this->applyEffects($id, $def, $born, $outcome->timerOps, $outcome->commands, $signal);

        return $born;
    }

    /**
     * Persist an advance: stamp the fan-out families, gate the declared state, update under OCC,
     * and apply the effects.
     *
     * @throws StaleWorkflowInstance when a competing step moved the OCC version underneath
     * @throws WorkflowStateRejected when the declared validator refuses the bag
     * @throws ChildrenStillRunning when a nominal settle would orphan living children
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable the tail of the effects, re-thrown
     */
    public function updated(WorkflowDefinition $def, WorkflowId $id, Updated $outcome, Signal $signal): void
    {
        $stamped = $this->familiesStamped($def, $outcome->row, $outcome->commands);
        $this->guardDeclaredState($def, $stamped);
        $this->instances->update($stamped); // OCC: WHERE version = loaded version
        $this->applyEffects($id, $def, $stamped, $outcome->timerOps, $outcome->commands, $signal);
    }

    /**
     * Persist an escalation: timers only, the row untouched.
     *
     * @throws SagaStorageFailure when the saga storage fails
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     */
    public function escalated(WorkflowId $id, Effects $outcome): void
    {
        foreach ($outcome->timerOps as $op) {
            $this->applyTimerOp($id, $op);
        }
    }

    /**
     * Persist a state migration outside a step's drive: the declared gate, then the OCC update, the
     * bag and its version in one write.
     *
     * @throws WorkflowStateRejected when the declared validator refuses the migrated bag
     * @throws StaleWorkflowInstance when a competing step moved the OCC version underneath
     * @throws SagaStateTooLarge when the migrated bags together exceed the state cap
     */
    public function migration(WorkflowDefinition $def, WorkflowInstanceRow $migrated): void
    {
        $this->guardDeclaredState($def, $migrated);
        $this->instances->update($migrated);
    }

    /**
     * The declared write-point gate: the workflow's optional `validateState()` judges the bag about
     * to be persisted, at birth and on every step alike. Sitting HERE, before the store write, it
     * covers every writer with one gate, an activity's result, a wait's extract, a fallback's
     * salvage vars or a signal handler, and a refusal rolls the whole step back before any effect.
     *
     * @throws WorkflowStateRejected wrapping the validator's own throw, the workflow's reason
     */
    private function guardDeclaredState(WorkflowDefinition $def, WorkflowInstanceRow $row): void
    {
        if ($def->stateValidator === null) {
            return;
        }

        try {
            ($def->stateValidator)($row->vars);
        } catch (Throwable $cause) {
            throw WorkflowStateRejected::at($row->workflowType, $row->correlationId, $row->stateKey, $cause);
        }
    }

    /**
     * The adoption proof of a birth that declares a parent, taken INSIDE the unit of work and
     * through the ordered adoption read, both halves of it. EXISTENCE: the parent must exist, run,
     * and be the type the declaration claims. CONSENT: the parent's own pinned definition must
     * declare the slot this child is born under, for this child's workflow type; a slot the parent
     * never declared, or declared for another child, is a composition bug and dead-letters, never
     * the announced-skip channel the races take.
     *
     * The ordering is the whole point. The parent's settle must take that row EXCLUSIVELY to move
     * its status, so the two steps cannot interleave unordered: the birth commits first and the
     * settle's cascade then sees the child, or the settle commits first and this read sees a
     * terminal parent and refuses. That closes the orphan window a lock-free check-then-act in the
     * spawner leaves open; the spawner's own guards stay as the cheap pre-check that avoids opening
     * a fence for a birth already known doomed.
     *
     * Consent reads the PARENT's pinned definition, the version the parent row was born under, the
     * same pinning every step honors: an in-flight parent of an old shape keeps the slots it declared
     * at birth, whatever a newer deployed version declares.
     *
     * Lock order is acyclic by construction: a birth takes the fence of the CHILD, then the ordered
     * read on the PARENT row; a settle takes the fence of the parent, then that row exclusively. No
     * step ever holds a child's fence while waiting on its own, so the two orders cannot close a
     * cycle, and the wait is bounded by one step of the parent.
     *
     * A root birth, no `$parent` in its context, reads nothing and pays nothing.
     *
     * @throws ParentNotAdoptable when the declared parent is gone, terminal, or another workflow type
     * @throws ChildSpawnRefused when the parent's definition never declared this slot, declared it for another child type, or the ceiling is reached
     * @throws InvalidChildIdentity when the row's own parent declaration is malformed
     * @throws WorkflowNotFound when no version of the parent's workflow type is registered
     * @throws WorkflowVersionNotFound when the parent's pinned version was purged while it still runs
     * @throws SagaStorageFailure when the saga storage fails
     */
    private function proveAdoption(WorkflowRegistry $registry, WorkflowInstanceRow $born): void
    {
        $ref = $born->parentRef();

        if ($ref === null) {
            return;
        }

        $parent = $this->families->loadAdoptableParent($ref->correlationId);

        if ($parent === null) {
            throw ParentNotAdoptable::missing($born->correlationId, $ref->correlationId);
        }

        if ($parent->workflowType !== $ref->workflowType) {
            throw ParentNotAdoptable::typeMismatch($born->correlationId, $ref->correlationId, $ref->workflowType, $parent->workflowType);
        }

        if ($parent->status !== WorkflowStatus::Running) {
            throw ParentNotAdoptable::terminal($born->correlationId, $ref->correlationId, $parent->status);
        }

        $declared = $registry->get($parent->workflowType, $parent->definitionVersion)->spawnFor($ref->slot);

        if ($declared === null) {
            throw ChildSpawnRefused::slotUndeclared($parent->workflowType, $ref->slot, $born->workflowType);
        }

        if ($declared->workflow !== $born->workflowType) {
            throw ChildSpawnRefused::slotChildMismatch($parent->workflowType, $ref->slot, $declared->workflow, $born->workflowType);
        }

        // the spawner's ceiling check is a lock-free pre-check two racing births can both pass at
        // 63 of 64; the AUTHORITATIVE count is taken here, serialized on the parent's spawn-lane
        // advisory lock inside the birth's own transaction, so the 65th is refused whatever the
        // interleaving. Benign with static slots, load-bearing under an indexed family's burst.
        $children = $this->families->countChildrenSerialized($ref->correlationId);
        if ($children >= ChildSpawner::MAX_CHILDREN) {
            throw ChildSpawnRefused::tooManyChildren($ref->correlationId, $children, ChildSpawner::MAX_CHILDREN);
        }
    }

    /**
     * Stamp the EXPECTED member counts of every indexed spawn family this step fans out onto the
     * row BEFORE it persists: the intention and the spawn commands commit in one write, so the
     * family completeness gate never counts against an expectation a crash could have lost. A step
     * that spawns no family member returns the row untouched, the free common case; a later step
     * may widen the same family, the expectation only ever grows.
     *
     * @param  list<IssuedCommand>  $commands
     */
    private function familiesStamped(WorkflowDefinition $def, WorkflowInstanceRow $row, array $commands): WorkflowInstanceRow
    {
        $counts = [];
        foreach ($commands as $issued) {
            $command = $issued->command;
            if (! $command instanceof StartChildWorkflow) {
                continue;
            }
            $declared = $def->spawnFor($command->slot);
            if ($declared !== null && $declared->indexed) {
                $counts[$declared->slot] = ($counts[$declared->slot] ?? 0) + 1;
            }
        }

        foreach ($counts as $family => $more) {
            $row = $row->expectingFamily((string) $family, $more);
        }

        return $row;
    }

    /**
     * Apply the collected effects within the step's transaction: the ordered `TimerOp`s, the issued
     * commands, the settling member's poke to its parent, and the settle-cleanup. A settled saga's pending timers are moot, dropped
     * conditionally: only the resting state's timer when that state arms one, and the global deadline
     * when the workflow declares one; crossings already dropped the left states' via their cancel ops.
     *
     * @param  list<TimerOp>  $ops  applied strictly in order, leave-then-re-enter: `cancel X … arm X`
     * @param  list<IssuedCommand>  $commands
     * @param  Signal  $signal  the step's trigger: an abort settle reads its `reason` and `force`
     *                          for the cascade, inherited as-is; an operator who forces the root
     *                          wants the tree dead, a nominal cascade compensates properly
     *
     * @throws ChildrenStillRunning when a NOMINAL settle would orphan living children; the step rolls back
     * @throws InvalidChildIdentity when a child row carries a malformed parent declaration
     * @throws InvalidDateTimeException when a timer's fire instant cannot be derived from `now`
     * @throws SerializationExceptionContract when an issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     */
    private function applyEffects(WorkflowId $id, WorkflowDefinition $def, WorkflowInstanceRow $resting, array $ops, array $commands, Signal $signal): void
    {
        foreach ($ops as $op) {
            $this->applyTimerOp($id, $op);
        }

        if ($resting->aborted()) {
            // The settle's recall, sibling of the timer cleanup below. An ABORTING saga, halted or
            // rolled back, never a normal completion whose pending commands may be legitimate
            // fire-and-forget, recalls every command still `pending` in its outbox: never claimed
            // by the relay, so still recallable, whether the issuing step declared a
            // compensation. Runs BEFORE the compensation's own commands are written, same tx, they
            // survive by ordering; what the relay already claimed stays published, row locks
            // arbitrate. A forced cancel's residual risk is commands genuinely
            // dispatched.
            $this->outbox->cancelPending($id);

            // The cascade, one CancelChildWorkflow per living child, written AFTER the recall so the
            // rows survive by the same ordering the compensation commands rely on. Always on an abort,
            // no knob: a detached process has its own door, the reaction that starts a saga. The waive
            // never reaches this block, a waived row keeps running. Transitivity is emergent: each
            // cancelled child settles through this same seam and cascades to its own children.
            foreach ($this->families->livingChildren($id->correlationId) as $child) {
                $this->outbox->write($id, CancelChildWorkflow::with(
                    $id->workflowType,
                    $id->correlationId,
                    $child->workflowType,
                    $child->correlationId,
                    $signal->reason,
                    $signal->force,
                ), $resting->stateKey, $resting->version, $resting->generation);
            }
        }

        if ($resting->status === WorkflowStatus::Completed) {
            // The settle guard's runtime floor: completing with living children would orphan the
            // tree, so the whole step rolls back, loudly. The fix is a terminal wait per spawned
            // slot in the parent's definition; the abort settles cascade above instead.
            $living = $this->families->livingChildren($id->correlationId);
            if ($living !== []) {
                throw ChildrenStillRunning::atNominalSettle(
                    $id->workflowType,
                    $id->correlationId,
                    count($living),
                    array_map(static fn ($slot): string => $slot->awaitedBy, $def->spawns),
                );
            }
        }

        if ($resting->status !== WorkflowStatus::Running) {
            // The family endgame's heal, written on the SETTLE and not on a conclusion, because only
            // a member's own terminal settle can complete its family: it is the last thing that
            // happens in the system before a parent holding an absorbed conclusion would sit at its
            // wait forever. Placed after the recall above and for the same reason the cascade is: an
            // aborting member mows its pending rows, and this one must survive that.
            //
            // Narrowed to a member of an INDEXED family, read off the slot's own grammar, so a
            // static-slot child costs nothing. The narrowing is exact rather than merely cheap: only
            // the indexed gate ever parks a crossing, a family can only become complete when one of
            // its own members settles, and the build reserves the member suffix on EVERY slot, so
            // nothing but a member can wear the form this reads.
            $ref = $resting->parentRef();
            if ($ref !== null && ChildCorrelation::familyOfSlot($ref->slot) !== null) {
                $this->outbox->write($id, PokeParentFamily::with(
                    $ref->workflowType,
                    $ref->correlationId,
                    $id->workflowType,
                    $id->correlationId,
                ), $resting->stateKey, $resting->version, $resting->generation);
            }
        }

        $this->fanOutMatchesItsArms($def, $commands);

        foreach ($commands as $issued) {
            // provenance rides to the row: the issuing state and the step marker, the resting row's OCC
            // version, unique per step, distinct across a cycle's re-visits; the settle's pairing input.
            // A command issued FROM a race or join state is stamped with its arm's effect_group,
            // resolved by command class, the targeted recall's key; the preflight above proved the
            // fan-out's shape, one command per arm, none smuggled, none missing, none doubled.
            $this->outbox->write($id, $issued->command, $issued->fromState, $resting->version, $resting->generation, $issued->effectGroup ?? $this->raceGroupFor($def, $issued) ?? $this->joinGroupFor($def, $issued));
        }

        if ($resting->status !== WorkflowStatus::Running) {
            // Cancel only what could exist. A halt in place,
            // a timeout that halts/compensates rather than transitions, strands its fired timer, so drop
            // the resting state's, but only when that state arms one: a completion rests on a FinalState,
            // and a halt may rest on an untimed wait, that armed nothing, where the cancel is a pure no-op
            // DELETE. Likewise, the global deadline is only when the workflow declares one. A crossing already
            // dropped the left states' timers via their cancel ops.
            if ($this->restingStateMayHaveTimer($def->state($resting->stateKey))) {
                $this->timers->cancel($id, $resting->stateKey);
            }
            if ($def->globalTimeout !== null) {
                $this->timers->cancel($id, WorkflowTimers::GLOBAL_KEY);
            }
        }
    }

    /**
     * Whether the settled saga's resting state could hold an armed timer worth a cancel, true only
     * for a state kind that arms one: an async or retrying activity, a schedule, or a timed wait. A
     * FinalState, a normal completion, and an untimed wait arm nothing, so their settle-cancel is a
     * pure no-op DELETE.
     */
    private function restingStateMayHaveTimer(State $state): bool
    {
        return $state instanceof ActivityState
            || $state instanceof ScheduleState
            || ($state instanceof WaitState && $state->timeout !== null);
    }

    /**
     * The fan-out's promised shape, enforced before its first outbox write: a race or join state
     * that issues native commands issues EXACTLY one per declared arm. The settlers take the
     * targeted recall's row count as proof, a positive count reading as "the arm never dispatched":
     * a duplicated arm would let a published command hide behind its recalled twin and skip the
     * undo, and a missing arm would starve the wait on a fan-out that lied about its width. Both
     * roll the step back loudly, the shape sibling of the unattributed refusals below. Two scope
     * cuts keep the proof honest:
     *
     * - Only commands WITHOUT an explicit `effectGroup` are counted: a settler's disposition is
     *   stamped with the arm it disposes of and issues from the same state key, never a fan-out;
     *
     * - The bijection is asserted only on a step whose native commands actually issue from the
     *   fan-out state, so a step that issues nothing from it, a disposition or an unrelated
     *   emission, proves nothing and is not judged.
     *
     * @param  list<IssuedCommand>  $commands
     *
     * @throws MalformedRaceFanOut when a race state's fan-out misses or duplicates an arm
     * @throws MalformedJoinFanOut when a join state's fan-out misses or duplicates an arm
     * @throws UnattributedRaceCommand when a race state issues a command no arm owns
     * @throws UnattributedJoinCommand when a join state issues a command no arm owns
     */
    private function fanOutMatchesItsArms(WorkflowDefinition $def, array $commands): void
    {
        $issuedArms = [];
        $fanOuts = [];
        foreach ($commands as $issued) {
            if ($issued->effectGroup !== null) {
                continue;
            }
            $state = $def->hasState($issued->fromState) ? $def->state($issued->fromState) : null;
            if (! $state instanceof ActivityState || ($state->race === null && $state->join === null)) {
                continue;
            }

            $arm = $state->race !== null
                ? ($state->race->armFor($issued->command::class) ?? throw UnattributedRaceCommand::forCommand($issued->command::class, $state->key, $def->name))->name
                : ($state->join->armFor($issued->command::class) ?? throw UnattributedJoinCommand::forCommand($issued->command::class, $state->key, $def->name))->name;

            if (isset($issuedArms[$state->key][$arm])) {
                throw $state->race !== null
                    ? MalformedRaceFanOut::armDuplicated($arm, $state->key, $def->name)
                    : MalformedJoinFanOut::armDuplicated($arm, $state->key, $def->name);
            }
            $issuedArms[$state->key][$arm] = true;
            $fanOuts[$state->key] = $state;
        }

        foreach ($fanOuts as $stateKey => $state) {
            $race = $state->race;
            foreach ($race->arms ?? $state->join->arms ?? [] as $slot) {
                if (! isset($issuedArms[$stateKey][$slot->name])) {
                    throw $race !== null
                        ? MalformedRaceFanOut::armMissing($slot->name, $stateKey, $def->name)
                        : MalformedJoinFanOut::armMissing($slot->name, $stateKey, $def->name);
                }
            }
        }
    }

    /**
     * The arm owning `$issued`, when its issuing state fans a race out: the outbox stamping key,
     * resolved by command class. Null for every non-race state, the ungrouped common case, and a
     * LOUD refusal for a race state smuggling a command no arm declared, since that command could
     * be neither recalled nor disposed of at the victory.
     *
     * @throws UnattributedRaceCommand when a race state issues a command no arm owns
     */
    private function raceGroupFor(WorkflowDefinition $def, IssuedCommand $issued): ?string
    {
        $state = $def->hasState($issued->fromState) ? $def->state($issued->fromState) : null;
        if (! $state instanceof ActivityState || $state->race === null) {
            return null;
        }

        $arm = $state->race->armFor($issued->command::class)
            ?? throw UnattributedRaceCommand::forCommand($issued->command::class, $state->key, $def->name);

        return $arm->name;
    }

    /**
     * The arm owning `$issued`, when its issuing state fans a join out: the race twin above, with
     * the same resolution, the same null and the same refusal. What the smuggled command escapes
     * here is the recall and the disposal owed when a sibling arm fails.
     *
     * @throws UnattributedJoinCommand when a join state issues a command no arm owns
     */
    private function joinGroupFor(WorkflowDefinition $def, IssuedCommand $issued): ?string
    {
        $state = $def->hasState($issued->fromState) ? $def->state($issued->fromState) : null;
        if (! $state instanceof ActivityState || $state->join === null) {
            return null;
        }

        $arm = $state->join->armFor($issued->command::class)
            ?? throw UnattributedJoinCommand::forCommand($issued->command::class, $state->key, $def->name);

        return $arm->name;
    }

    /**
     * The one place the clock turns a relative instruction into a fire instant, floored to 1s.
     *
     * @throws SagaStorageFailure when the saga storage fails, with driver failures wrapped by the adapter
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     */
    private function applyTimerOp(WorkflowId $id, TimerOp $op): void
    {
        match ($op->kind) {
            TimerOpKind::ArmTimeout => $this->timers->arm($id, $op->stateKey, TimerKind::Timeout, $this->armAt($op)),
            TimerOpKind::ArmGlobal => $this->timers->arm($id, $op->stateKey, TimerKind::Global, $this->armAt($op)),
            TimerOpKind::ArmKick => $this->timers->arm($id, $op->stateKey, TimerKind::Kick, $this->kickAt($op->delayMs)),
            TimerOpKind::ArmSchedule => $this->timers->arm($id, $op->stateKey, TimerKind::Schedule, $this->scheduleAt($op)),
            TimerOpKind::CancelState, TimerOpKind::CancelGlobal => $this->timers->cancel($id, $op->stateKey),
        };
    }

    /**
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     * @throws BusinessCalendarMissing when a business-time arm has no BusinessCalendar bound
     */
    private function armAt(TimerOp $op): PointInTime
    {
        if ($op->businessDays !== null || $op->businessHours !== null) {
            $calendar = $this->calendar ?? throw BusinessCalendarMissing::forArm($op->stateKey);

            // in the single place a business deadline turns relative to absolute, with weekends/holidays/hours skipped
            return $calendar->advance($this->clock->now(), $op->businessDays ?? 0, $op->businessHours ?? 0);
        }

        /** @var int $seconds the armTimeout()/armGlobal() factories always set it */
        $seconds = $op->seconds;

        return $this->clock->now()->addSeconds(max(1, $seconds));
    }

    /**
     * A kick has an optional back-off delay; sub-second is rounded up to the 1s sweep granularity,
     * and null means now.
     *
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     */
    private function kickAt(?int $delayMs): PointInTime
    {
        $now = $this->clock->now();

        return $delayMs === null
            ? $now
            : $now->addSeconds(max(1, (int) ceil($delayMs / 1000)));
    }

    /**
     * Schedule op carries an absolute instant, a calendar slot, armed as-is and not relative to now.
     */
    private function scheduleAt(TimerOp $op): PointInTime
    {
        /** @var PointInTime $at the armScheduleAt() factory always sets it */
        $at = $op->at;

        return $at;
    }
}
