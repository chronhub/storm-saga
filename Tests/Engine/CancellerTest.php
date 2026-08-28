<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Canceller;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Event\SagaCancelled;
use Storm\Saga\Event\SagaCompensated;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The operator's cancel: halt where the saga sits, roll back POSITIONALLY, since progression proves
 * completion, and `SagaCancelled(reason)` announced FIRST. Invariant 4 is the safety net: even
 * under `--force`, an unconfirmed tracked step is skipped+flagged, never blindly compensated.
 */
final class CancellerTest extends TestCase
{
    #[Test]
    public function it_halts_and_rolls_back_positionally_announcing_cancelled_first(): void
    {
        $row = $this->row('lobby', log: [CompensationRecord::pending('charge')->confirm()]);

        $run = $this->canceller()->cancel($this->def(), $row, 'duplicate order', null);

        $this->assertSame(WorkflowStatus::Compensated, $run->row->status);
        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status);

        $this->assertInstanceOf(SagaCancelled::class, $run->announcements[0]); // the operator's word FIRST
        $this->assertSame('duplicate order', $run->announcements[0]->reason);
        $this->assertSame('lobby', $run->announcements[0]->state);             // where it sat
        $this->assertCount(1, array_filter($run->announcements, static fn (object $e): bool => $e instanceof SagaCompensated));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_forced_cancel_never_blindly_compensates_the_in_flight_step(): void
    {
        // forced at the gating wait: charge confirmed, rolls back; credit in flight = tracked
        // unconfirmed, so SKIPPED+flagged; invariant 4 holds even against the operator
        $row = $this->row('await', log: [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('credit'),
        ]);

        $run = $this->canceller()->cancel($this->def(), $row, 'ops emergency', null);

        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status);
        $this->assertSame(CompensationStatus::Skipped, $run->row->compensations[1]->status);
        $this->assertSame('unconfirmed', $run->row->compensations[1]->reason);
    }

    #[Test]
    public function the_rollback_is_positional_an_untracked_step_is_undone(): void
    {
        // 'fee' is compensable but has NO confirmedBy: progression past it proves it ran;
        // the POSITIONAL rollback undoes it, where a location-agnostic one would have skipped it
        $row = $this->row('lobby', log: [CompensationRecord::pending('fee')]);

        $run = $this->canceller()->cancel($this->defWithUntrackedFee(), $row, null, null);

        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status);
    }

    #[Test]
    public function with_nothing_to_undo_it_halts_where_it_sat(): void
    {
        $run = $this->canceller()->cancel($this->def(), $this->row('lobby'), null, null);

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertSame('lobby', $run->row->stateKey);
        $this->assertInstanceOf(SagaCancelled::class, $run->announcements[0]);
        $this->assertNull($run->announcements[0]->reason); // the reason is optional
    }

    private function canceller(): Canceller
    {
        return new Canceller(new Compensator(new MutableClock));
    }

    private function def(): WorkflowDefinition
    {
        $charge = new ActivityState(
            'charge',
            new RecordingActivity(ActivityResult::success()),
            compensation: new RecordingActivity(ActivityResult::success()),
            compensationConfirmedBy: stdClass::class,
            transitions: [new Transition(OnTrigger::Success, 'await')],
        );
        $credit = new ActivityState(
            'credit',
            new RecordingActivity(ActivityResult::success()),
            compensation: new RecordingActivity(ActivityResult::success()),
            compensationConfirmedBy: SampleEvent::class,
            transitions: [new Transition(OnTrigger::Success, 'await')],
        );

        return new WorkflowDefinition('wf', [
            'charge' => $charge,
            'credit' => $credit,
            'await' => new WaitState('await', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'lobby' => new WaitState('lobby', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
        ], 'charge');
    }

    private function defWithUntrackedFee(): WorkflowDefinition
    {
        $fee = new ActivityState(
            'fee',
            new RecordingActivity(ActivityResult::success()),
            compensation: new RecordingActivity(ActivityResult::success()),
            transitions: [new Transition(OnTrigger::Success, 'lobby')],
        );

        return new WorkflowDefinition('wf', [
            'fee' => $fee,
            'lobby' => new WaitState('lobby', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
        ], 'fee');
    }

    /**
     * @param  list<CompensationRecord>  $log
     */
    private function row(string $stateKey, array $log = []): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('wf', 'c-1', $stateKey, WorkflowStatus::Running, [], [], [], 0, new PointInTime, $log);
    }
}
