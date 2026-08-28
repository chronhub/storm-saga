<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\MachineRunner;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Event\SagaCompleted;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Unit coverage of the pure drive loop: the chain bound, the first-hop-only stimulus, the
 * compensation-log accrual and confirmation, the ORDERED TimerOps of a leave-then-re-enter, the
 * per-visit retry budget, and the five resting rows of the cursor.
 */
final class MachineRunnerTest extends TestCase
{
    #[Test]
    public function a_synchronous_chain_runs_until_it_rests(): void
    {
        // charge on success goes to await, a wait with timeout 30: one run crosses and rests armed
        $run = $this->machine()->run($this->def(), $this->row('charge'), Stimulus::none(), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('await', $run->row->stateKey);
        $this->assertSame(WorkflowStatus::Running, $run->row->status);

        // crossing logged the compensatable step as pending intent
        $this->assertCount(1, $run->row->compensations);
        $this->assertSame('charge', $run->row->compensations[0]->step);
        $this->assertSame(CompensationStatus::Pending, $run->row->compensations[0]->status);

        // ops in order: leave charge, then arm the wait's timeout
        $this->assertSame(TimerOpKind::CancelState, $run->timerOps[0]->kind);
        $this->assertSame('charge', $run->timerOps[0]->stateKey);
        $this->assertSame(TimerOpKind::ArmTimeout, $run->timerOps[1]->kind);
        $this->assertSame('await', $run->timerOps[1]->stateKey);
        $this->assertSame(30, $run->timerOps[1]->seconds);

        // and the hop announced itself
        $hops = array_values(array_filter($run->announcements, static fn (object $e): bool => $e instanceof SagaTransitioned));
        $this->assertCount(1, $hops);
        $this->assertSame('charge', $hops[0]->from);
        $this->assertSame('await', $hops[0]->to);
    }

    #[Test]
    public function the_chain_bound_throws_on_a_transition_cycle(): void
    {
        // two activities feeding each other success; the synchronous chain can never rest
        $a = new ActivityState('a', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'b')]);
        $b = new ActivityState('b', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'a')]);
        $def = new WorkflowDefinition('wf', ['a' => $a, 'b' => $b], 'a');

        $this->expectException(WorkflowStepLimitExceeded::class);

        $this->machine()->run($def, $this->row('a'), Stimulus::none(), new PointInTime, null);
    }

    #[Test]
    public function the_chain_bound_allows_exactly_state_count_plus_one_runs(): void
    {
        // a 1-state self-loop: limit = 2, so hops 0,1,2 run as three executions, and hop 3 trips the bound.
        // the EXACT count pins the bound's arithmetic; a ±1 or >= would run 2 or 4 times
        $activity = new RecordingActivity(ActivityResult::success());
        $a = new ActivityState('a', $activity, transitions: [new Transition(OnTrigger::Success, 'a')]);
        $def = new WorkflowDefinition('wf', ['a' => $a], 'a');

        try {
            $this->machine()->run($def, $this->row('a'), Stimulus::none(), new PointInTime, null);
            $this->fail('the self-loop must trip the chain bound');
        } catch (WorkflowStepLimitExceeded) {
            $this->assertSame(3, $activity->calls);
        }
    }

    #[Test]
    public function the_stimulus_applies_to_the_first_hop_only(): void
    {
        // await1 --event--> await2, untimed and also matching SampleEvent: if the event leaked into hop 1,
        // await2 would match it too and the run would land on `done`; it must rest ON await2 instead
        $await1 = new WaitState('await1', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'await2')]);
        $await2 = new WaitState('await2', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]);
        $def = new WorkflowDefinition('wf', ['await1' => $await1, 'await2' => $await2, 'done' => new FinalState('done')], 'await1');

        $run = $this->machine()->run($def, $this->row('await1'), Stimulus::event(new SampleEvent), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('await2', $run->row->stateKey);
        $this->assertSame(WorkflowStatus::Running, $run->row->status);

        // leaving the UNTIMED await1 cancels nothing; an untimed wait armed no timer, so no cancelState
        // is owed. Pins the `instanceof WaitState && timeout !== null` arm of leftStateMayHaveArmedTimer
        $this->assertCount(0, array_filter(
            $run->timerOps,
            static fn ($op): bool => $op->kind === TimerOpKind::CancelState && $op->stateKey === 'await1',
        ));
    }

    #[Test]
    public function an_unmatched_event_on_an_untimed_wait_is_unmoved(): void
    {
        $lobby = new WaitState('lobby', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]);
        $def = new WorkflowDefinition('wf', ['lobby' => $lobby, 'done' => new FinalState('done')], 'lobby');

        $run = $this->machine()->run($def, $this->row('lobby'), Stimulus::event(new DateTimeImmutable), new PointInTime, null);

        $this->assertInstanceOf(Unmoved::class, $run);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unmatched_event_on_a_timed_wait_never_pushes_its_deadline(): void
    {
        // the starvation guard: an unmatched event leaves the wait UNMOVED. Stay-and-re-arm would
        // let correlated chatter arriving faster than the timeout move the deadline forever, so it
        // would never fire; instead the timer armed at entry stands, with no re-arm and no row
        // write, so a stray event causes no OCC churn.
        $run = $this->machine()->run($this->def(), $this->row('await'), Stimulus::event(new DateTimeImmutable), new PointInTime, null);

        $this->assertInstanceOf(Unmoved::class, $run);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_event_delivered_to_an_activity_resting_on_a_retry_back_off_re_executes_it_immediately(): void
    {
        // THE WAKE CONTRACT, retry-Stay half: an activity neither reads nor
        // matches an event stimulus; the delivery wakes the machine and the runner re-executes NOW.
        // The back-off spaces the engine's KICKS, not external wakes: an event landing in the window
        // re-runs immediately and burns the attempt; a retried activity is re-entrant by contract,
        // one more re-run source on top of at-least-once redelivery. No double timer: the fresh
        // kick's upsert replaces the still-pending one.
        $flaky = new RecordingActivity(ActivityResult::failure('downstream down'));
        $state = new ActivityState('flaky', $flaky, retry: new RetryPolicy(maxAttempts: 3, jitter: false), transitions: [new Transition(OnTrigger::Failure, 'done')]);
        $def = new WorkflowDefinition('wf', ['flaky' => $state, 'done' => new FinalState('done')], 'flaky');
        $row = $this->row('flaky', retries: ['flaky' => ['n' => 1, 'since' => null]]); // mid back-off: attempt 1 failed, its kick is still pending

        $run = $this->machine()->run($def, $row, Stimulus::event(new SampleEvent), new PointInTime, null);

        $this->assertSame(1, $flaky->calls); // re-executed by the event, not by the armed kick
        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('flaky', $run->row->stateKey);
        $this->assertSame(2, $run->row->retries['flaky']['n']); // an attempt burned out of tempo
        $this->assertSame(TimerOpKind::ArmKick, $run->timerOps[0]->kind); // a fresh back-off re-armed
    }

    #[Test]
    #[Group('adversarial')]
    public function an_early_outcome_event_is_swallowed_by_the_retry_re_run_and_never_reaches_its_wait(): void
    {
        // THE WAKE CONTRACT, the accepted residual: the OUTCOME event arrives
        // during the back-off window of the very activity that triggers the effect. It wakes the
        // machine, hop 0 being the re-run which succeeds this time, and the machine crosses INTO the wait
        // the event was meant for. But the stimulus applies to the first hop only: the wait rests
        // unmatched, waiting for an event that already came and was acked. BY DESIGN nothing buffers
        // or re-delivers it engine-side; the wait's own liveness, whether timeout, escalation, or an at-rest
        // reconciler, owns the strand. Buffering was weighed and rejected: selection
        // needs a matcher whose vars don't exist yet, replay re-opens every deadline guard, and the
        // race is narrow and app-mitigable at the source, via ordered dispatch or at-rest backstops.
        $trigger = new RecordingActivity(ActivityResult::success());
        $state = new ActivityState('trigger', $trigger, retry: new RetryPolicy(maxAttempts: 3, jitter: false), transitions: [new Transition(OnTrigger::Success, 'confirmed')]);
        $confirmed = new WaitState('confirmed', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]);
        $def = new WorkflowDefinition('wf', ['trigger' => $state, 'confirmed' => $confirmed, 'done' => new FinalState('done')], 'trigger');
        $row = $this->row('trigger', retries: ['trigger' => ['n' => 1, 'since' => null]]); // resting mid back-off after one failure

        $run = $this->machine()->run($def, $row, Stimulus::event(new SampleEvent), new PointInTime, null);

        $this->assertSame(1, $trigger->calls);
        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('confirmed', $run->row->stateKey); // NOT `done`: the event was consumed at hop 0, the wait strands
    }

    #[Test]
    public function an_event_delivered_to_an_async_resting_activity_wakes_and_re_runs_it(): void
    {
        // THE WAKE CONTRACT, async half; why an event never skips on state: an
        // async activity's result "returns as an event"; the delivery
        // wakes the machine, the activity re-runs and re-checks its job, the ref riding vars. Pinned
        // side effect: the re-run's Stay re-arms the async deadline, so every delivered event pushes
        // the timeout out.
        $issue = new RecordingActivity(ActivityResult::async('job-1', ['sent' => true]));
        $state = new ActivityState('issue', $issue, timeout: new Timeout(10));
        $def = new WorkflowDefinition('wf', ['issue' => $state], 'issue');

        $run = $this->machine()->run($def, $this->row('issue'), Stimulus::event(new SampleEvent), new PointInTime, null);

        $this->assertSame(1, $issue->calls); // woken and re-run, the async completion contract
        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('issue', $run->row->stateKey);
        $this->assertSame(TimerOpKind::ArmTimeout, $run->timerOps[0]->kind); // deadline pushed by the wake
    }

    #[Test]
    public function crossing_resets_the_left_states_retry_budget(): void
    {
        // the budget is per-visit: leaving `charge` drops its counter, the other states' survive
        $row = $this->row('charge', retries: ['charge' => ['n' => 2, 'since' => null], 'other' => ['n' => 1, 'since' => null]]);

        $run = $this->machine()->run($this->def(), $row, Stimulus::none(), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(['other' => ['n' => 1, 'since' => null]], $run->row->retries);
    }

    #[Test]
    public function ops_stay_ordered_across_a_leave_and_re_enter(): void
    {
        // w --event--> a --success--> w, timed: the SAME state is left then re-armed in one run;
        // out of order, `arm w` before `cancel w` would strand the wait without its timer. 'a' is a
        // synchronous intermediate: never rested, so never armed, so it emits no cancelState.
        $w = new WaitState('w', eventClasses: [SampleEvent::class], timeout: new Timeout(5), transitions: [new Transition(OnTrigger::Event, 'a')]);
        $a = new ActivityState('a', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'w')]);
        $def = new WorkflowDefinition('wf', ['w' => $w, 'a' => $a], 'w');

        $run = $this->machine()->run($def, $this->row('w'), Stimulus::event(new SampleEvent), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $kinds = array_map(static fn ($op) => [$op->kind, $op->stateKey], $run->timerOps);
        $this->assertSame([
            [TimerOpKind::CancelState, 'w'],
            [TimerOpKind::ArmTimeout, 'w'],
        ], $kinds);
    }

    #[Test]
    public function a_resolving_event_confirms_the_logged_step(): void
    {
        // stdClass is charge's confirmedBy: delivered at the gating wait, it flips the pending record
        $row = $this->row('await', log: [CompensationRecord::pending('charge')]);

        $run = $this->machine()->run($this->def(), $row, Stimulus::event(new stdClass), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertTrue($run->row->compensations[0]->confirmed);
    }

    #[Test]
    public function a_halt_announces_itself_only_with_an_empty_log(): void
    {
        // a timeout on `await` has no Timeout edge, so Halt. With a log, the caller compensates;
        // announcing SagaHalted here would be a lie; with an empty log it IS the terminal word.
        $bare = $this->machine()->run($this->def(), $this->row('await'), Stimulus::timeout(), new PointInTime, null);
        $logged = $this->machine()->run(
            $this->def(),
            $this->row('await', log: [CompensationRecord::pending('charge')]),
            Stimulus::timeout(),
            new PointInTime,
            null,
        );

        $this->assertInstanceOf(Rested::class, $bare);
        $this->assertSame(WorkflowStatus::Halted, $bare->row->status);
        $this->assertCount(1, array_filter($bare->announcements, static fn (object $e): bool => $e instanceof SagaHalted));

        $this->assertInstanceOf(Rested::class, $logged);
        $this->assertSame(WorkflowStatus::Halted, $logged->row->status);
        $this->assertCount(0, array_filter($logged->announcements, static fn (object $e): bool => $e instanceof SagaHalted));
    }

    #[Test]
    public function reaching_a_final_state_completes_and_announces(): void
    {
        // await --(stdClass)--> done
        $run = $this->machine()->run($this->def(), $this->row('await'), Stimulus::event(new stdClass), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(WorkflowStatus::Completed, $run->row->status);
        $this->assertCount(1, array_filter($run->announcements, static fn (object $e): bool => $e instanceof SagaCompleted));
    }

    #[Test]
    public function an_async_stay_carries_its_commands_and_authoritative_vars(): void
    {
        $issued = new stdClass;
        $async = new ActivityState('issue', new RecordingActivity(ActivityResult::async('job-1', ['sent' => true], [$issued])), timeout: new Timeout(10));
        $def = new WorkflowDefinition('wf', ['issue' => $async], 'issue');

        $run = $this->machine()->run($def, $this->row('issue', vars: ['old' => 1]), Stimulus::none(), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(['sent' => true], $run->row->vars); // the Stay's vars are authoritative
        $this->assertSame('issue', $run->commands[0]->fromState); // the resting state is the provenance
        $this->assertSame($issued, $run->commands[0]->command);
        $this->assertSame(TimerOpKind::ArmTimeout, $run->timerOps[0]->kind);
    }

    #[Test]
    public function it_dispatches_a_schedule_state_to_the_schedule_runner(): void
    {
        // the schedule arm of the subtype match: a ScheduleState must reach the ScheduleRunner, which arms
        // its next slot, not fall through to an unhandled match
        $await = new ScheduleState('await', new IntervalCadence(3600), new CatchUpPolicy(CatchUp::Skip), [new Transition(OnTrigger::Schedule, 'done')]);
        $def = new WorkflowDefinition('sched', ['await' => $await, 'done' => new FinalState('done')], 'await');

        $run = $this->machine()->run($def, $this->row('await'), Stimulus::none(), new PointInTime, null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('await', $run->row->stateKey);
        $this->assertSame(TimerOpKind::ArmSchedule, $run->timerOps[0]->kind); // the schedule runner armed the next slot
    }

    #[Test]
    public function the_schedule_arm_phases_the_grid_on_the_instances_started_at(): void
    {
        // MachineRunner threads the row's startedAt into the context as the anchor, so the re-armed slot
        // is phased on the saga's birth, :17 past, not on the step instant of 10:30, where a naive grid would arm 11:30
        $await = new ScheduleState('await', new IntervalCadence(3600), new CatchUpPolicy(CatchUp::Skip), [new Transition(OnTrigger::Schedule, 'done')]);
        $def = new WorkflowDefinition('sched', ['await' => $await, 'done' => new FinalState('done')], 'await');
        $row = $this->row('await', startedAt: PointInTime::from('2026-06-15T10:17:00.000000Z'));

        $run = $this->machine()->run($def, $row, Stimulus::none(), PointInTime::from('2026-06-15T10:30:00.000000Z'), null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('2026-06-15T11:17:00.000000+00:00', $run->timerOps[0]->at?->toString());
    }

    #[Test]
    public function the_schedule_arm_falls_back_to_now_when_the_row_has_no_started_at(): void
    {
        // the type-nullable started_at edge, never reached in practice since the column is NOT NULL: MachineRunner
        // coalesces a missing anchor to now, so the grid phases on the step instant, un-phased but never wrong
        $await = new ScheduleState('await', new IntervalCadence(3600), new CatchUpPolicy(CatchUp::Skip), [new Transition(OnTrigger::Schedule, 'done')]);
        $def = new WorkflowDefinition('sched', ['await' => $await, 'done' => new FinalState('done')], 'await');
        $row = new WorkflowInstanceRow('wf', 'c-1', 'await', WorkflowStatus::Running, [], [], [], 0, null, []);

        $run = $this->machine()->run($def, $row, Stimulus::none(), PointInTime::from('2026-06-15T10:30:00.000000Z'), null);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('2026-06-15T11:30:00.000000+00:00', $run->timerOps[0]->at?->toString()); // now + one period
    }

    private function machine(): MachineRunner
    {
        $selector = new TransitionSelector;

        return new MachineRunner(
            new ActivityRunner($selector),
            new WaitRunner($selector, new StubEventResolver),
            new ScheduleRunner($selector),
            new FinalRunner,
            new Compensator(new MutableClock),
        );
    }

    /**
     * The fixture graph. `charge` is an activity whose compensation is confirmed by `stdClass` and
     * whose success leads to `await`; `await` is a wait with a 30s timeout that takes `stdClass` to
     * `done` and `SampleEvent` back to `charge`; `done` is final.
     */
    private function def(): WorkflowDefinition
    {
        $charge = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::success()),
            timeout: new Timeout(60),
            compensation: new RecordingActivity(ActivityResult::success()),
            compensationConfirmedBy: stdClass::class,
            transitions: [new Transition(OnTrigger::Success, 'await')]);

        $await = new WaitState('await', eventClasses: [stdClass::class, SampleEvent::class], timeout: new Timeout(30), transitions: [new Transition(OnTrigger::Event, 'done', onEvent: stdClass::class), new Transition(OnTrigger::Event, 'charge', onEvent: SampleEvent::class)]);

        return new WorkflowDefinition('wf', ['charge' => $charge, 'await' => $await, 'done' => new FinalState('done')], 'charge');
    }

    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{n: int, since: string|null}>  $retries
     * @param  list<CompensationRecord>  $log
     */
    private function row(string $stateKey, array $vars = [], array $retries = [], array $log = [], ?PointInTime $startedAt = null): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('wf', 'c-1', $stateKey, WorkflowStatus::Running, $vars, $retries, [], 0, $startedAt ?? new PointInTime, $log);
    }
}
