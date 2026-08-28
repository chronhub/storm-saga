<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\DeadlineEnforcer;
use Storm\Saga\Engine\MachineRunner;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Event\SagaAwaitOverdue;
use Storm\Saga\Event\SagaCompensated;
use Storm\Saga\Event\SagaCompensationSkipped;
use Storm\Saga\Event\SagaCompleted;
use Storm\Saga\Event\SagaGloballyTimedOut;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The elapsed global deadline's three branches, each with its pinned announcement ORDER, and the
 * leading cancel ops, the current state's timers + the consumed deadline, whatever the branch.
 */
final class DeadlineEnforcerTest extends TestCase
{
    #[Test]
    public function a_confirmed_step_rolls_back_and_supersedes_the_timeout_routing(): void
    {
        // charge is confirmed, so compensate location-agnostically, even though onGlobalTimeout exists
        $row = $this->row('await', log: [CompensationRecord::pending('charge')->confirm()]);

        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: 'failed'), $row, new PointInTime, null);

        $this->assertSame(WorkflowStatus::Compensated, $run->row->status);
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]); // timedOut FIRST
        $this->assertCount(1, $this->of($run->announcements, SagaCompensated::class));
        $this->assertCount(0, $this->of($run->announcements, SagaTransitioned::class)); // no onGlobalTimeout routing

        $this->assertSame(TimerOpKind::CancelState, $run->timerOps[0]->kind);
        $this->assertSame('await', $run->timerOps[0]->stateKey);
        $this->assertSame(TimerOpKind::CancelGlobal, $run->timerOps[1]->kind);
    }

    #[Test]
    public function the_rollback_is_location_agnostic_skipping_the_untracked_sibling(): void
    {
        // charge is confirmed and undone; refund is untracked, so at a deadline its effect is UNVERIFIABLE
        // and must be skipped+flagged; a positional rollback would have undone it
        $row = $this->row('await', log: [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('refund'),
        ]);

        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: null), $row, new PointInTime, null);

        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status);
        $this->assertSame(CompensationStatus::Skipped, $run->row->compensations[1]->status);
        $this->assertSame('unverifiable at global deadline', $run->row->compensations[1]->reason);

        // both compensation announcements ride after timedOut; none lost to a half-spread
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertCount(1, $this->of($run->announcements, SagaCompensated::class));
        $this->assertCount(1, $this->of($run->announcements, SagaCompensationSkipped::class));
        $this->assertCount(2, $run->timerOps); // the two leading cancels, nothing nested
    }

    #[Test]
    public function the_forced_drive_announcements_ride_in_full_and_in_order(): void
    {
        // `cleanup` is an activity whose success routes to failed: the forced drive announces ITS hop and the
        // completion; every announcement must survive the merge, the skipped flag last
        $row = $this->row('await', log: [CompensationRecord::pending('charge')]);

        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: 'cleanup'), $row, new PointInTime, null);

        $this->assertSame(WorkflowStatus::Completed, $run->row->status);
        $this->assertCount(5, $run->announcements);
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[1]);   // the forced hop
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[2]);   // cleanup to failed
        $this->assertInstanceOf(SagaCompleted::class, $run->announcements[3]);
        $this->assertInstanceOf(SagaCompensationSkipped::class, $run->announcements[4]);
        // the two leading cancels + the drive's own cancel of `cleanup`; flat, complete, in order
        $this->assertSame(
            [TimerOpKind::CancelState, TimerOpKind::CancelGlobal, TimerOpKind::CancelState],
            array_map(static fn ($op) => $op->kind, $run->timerOps),
        );
    }

    #[Test]
    public function nothing_safe_to_undo_and_no_routing_halts_flagging_the_unverifiable(): void
    {
        // an unconfirmed tracked step: unverifiable at a location-agnostic deadline, so halt + flag
        $row = $this->row('await', log: [CompensationRecord::pending('charge')]);

        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: null), $row, new PointInTime, null);

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaHalted::class, $run->announcements[1]);
        $this->assertInstanceOf(SagaCompensationSkipped::class, $run->announcements[2]);
        $this->assertSame(['charge'], $run->announcements[2]->states);
    }

    #[Test]
    public function an_empty_log_halts_without_the_skipped_flag(): void
    {
        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: null), $this->row('await'), new PointInTime, null);

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertCount(2, $run->announcements); // timedOut, halted; nothing skipped to flag
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaHalted::class, $run->announcements[1]);
    }

    #[Test]
    public function the_declared_routing_drives_from_the_on_global_timeout_state(): void
    {
        // `failed` is a Final, so the forced drive completes the saga
        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: 'failed'), $this->row('await'), new PointInTime, null);

        $this->assertSame(WorkflowStatus::Completed, $run->row->status);
        $this->assertSame('failed', $run->row->stateKey);

        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[1]); // the forced hop
        $this->assertSame('failed', $run->announcements[1]->to);
        $this->assertInstanceOf(SagaCompleted::class, $run->announcements[2]);    // the drive's own word

        $this->assertSame(TimerOpKind::CancelState, $run->timerOps[0]->kind);
        $this->assertSame(TimerOpKind::CancelGlobal, $run->timerOps[1]->kind);
    }

    #[Test]
    public function an_unmoved_forced_drive_persists_the_forced_row_as_is(): void
    {
        // onGlobalTimeout = a bare untimed wait: the forced drive moves nothing; the forced row stands
        $run = $this->enforcer()->enforce($this->def(onGlobalTimeout: 'limbo'), $this->row('await'), new PointInTime, null);

        $this->assertSame(WorkflowStatus::Running, $run->row->status);
        $this->assertSame('limbo', $run->row->stateKey);
        $this->assertCount(2, $run->announcements); // timedOut + the forced hop, nothing more
    }

    #[Test]
    public function the_cap_on_a_gating_wait_halts_and_flags_even_a_confirmed_step(): void
    {
        // the global cap fell on an effect-gating wait. The KEY difference from enforce(): even a
        // CONFIRMED step, which enforce() would roll back, is NOT compensated here; releasing an
        // in-flight effect at the cap would create money. Halt + flag for reconciliation, drive nothing.
        $row = $this->row('await', log: [CompensationRecord::pending('charge')->confirm()]);

        $run = $this->enforcer()->haltAtCap($row);

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]); // timedOut FIRST
        $this->assertInstanceOf(SagaHalted::class, $run->announcements[1]);
        $this->assertInstanceOf(SagaCompensationSkipped::class, $run->announcements[2]);
        $this->assertSame(['charge'], $run->announcements[2]->states);
        // it rolled nothing back and issued no command; the money-loss guard
        $this->assertCount(0, $this->of($run->announcements, SagaCompensated::class));
        $this->assertCount(0, $this->of($run->announcements, SagaTransitioned::class));
        $this->assertSame([], $run->commands);
        // the leading cancels: the current state's timer + the consumed global deadline
        $this->assertSame(
            [TimerOpKind::CancelState, TimerOpKind::CancelGlobal],
            array_map(static fn ($op) => $op->kind, $run->timerOps),
        );
    }

    #[Test]
    public function the_cap_on_a_gating_wait_with_an_empty_log_halts_without_the_skip_flag(): void
    {
        $run = $this->enforcer()->haltAtCap($this->row('await'));

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertCount(2, $run->announcements); // timedOut, halted; nothing logged to flag
        $this->assertInstanceOf(SagaGloballyTimedOut::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaHalted::class, $run->announcements[1]);
    }

    #[Test]
    public function the_cap_on_a_retriable_gating_wait_disarms_both_timers_stamps_the_waive_and_hands_off(): void
    {
        // the global cap fell on a RETRIABLE effect-gating wait: success is inevitable, so it neither halts
        // nor finalizes, per inv. 2. It disarms BOTH timers, the spent global AND the wait's heartbeat, which
        // would re-arm for nothing on a never-confirming effect; it stamps `waived_at` on the row, the
        // DURABLE trace read by the sweep and the straggler guard, and emits a one-shot SagaAwaitOverdue
        // handing off to reconciliation. The saga stays Running, quiet.
        $now = PointInTime::from('2026-07-02T10:00:00.000000+00:00');

        $run = $this->enforcer()->waiveAtCap($this->row('await'), $now);

        $this->assertSame(WorkflowStatus::Running, $run->row->status); // non-terminal; a late outcome still resolves
        $this->assertSame($now, $run->row->waivedAt);                  // the durable trace
        $this->assertSame(
            [TimerOpKind::CancelState, TimerOpKind::CancelGlobal],
            array_map(static fn ($op) => $op->kind, $run->timerOps),
        );
        $this->assertSame('await', $run->timerOps[0]->stateKey); // the heartbeat, by the wait's state key
        // a one-shot hand-off, NOT silent, and distinct from the recurring SagaAwaitEscalated heartbeat
        $this->assertCount(1, $run->announcements);
        $this->assertInstanceOf(SagaAwaitOverdue::class, $run->announcements[0]);
        $this->assertSame('await', $run->announcements[0]->stateKey);
        $this->assertSame([], $run->commands);
    }

    private function enforcer(): DeadlineEnforcer
    {
        $selector = new TransitionSelector;
        $compensator = new Compensator(new MutableClock);
        $machine = new MachineRunner(
            new ActivityRunner($selector),
            new WaitRunner($selector, new StubEventResolver),
            new ScheduleRunner($selector),
            new FinalRunner,
            $compensator,
        );

        return new DeadlineEnforcer($machine, $compensator);
    }

    private function def(?string $onGlobalTimeout): WorkflowDefinition
    {
        $charge = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::success()),
            compensation: new RecordingActivity(ActivityResult::success()),
            compensationConfirmedBy: stdClass::class,
            transitions: [new Transition(OnTrigger::Success, 'await')]);

        $states = [
            'charge' => $charge,
            'refund' => new ActivityState('refund', new RecordingActivity(ActivityResult::success()), compensation: new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await')]),
            'cleanup' => new ActivityState('cleanup', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'failed')]),
            'await' => new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'limbo' => new WaitState('limbo', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
            'failed' => new FinalState('failed'),
        ];

        return new WorkflowDefinition('wf', $states, 'charge', globalTimeout: 60, onGlobalTimeout: $onGlobalTimeout);
    }

    /**
     * @param  list<CompensationRecord>  $log
     */
    private function row(string $stateKey, array $log = []): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('wf', 'c-1', $stateKey, WorkflowStatus::Running, [], [], [], 0, new PointInTime, $log);
    }

    /**
     * @template T of object
     *
     * @param  list<object>  $events
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private function of(array $events, string $class): array
    {
        return array_values(array_filter($events, static fn (object $e): bool => $e instanceof $class));
    }
}
