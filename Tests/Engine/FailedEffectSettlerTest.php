<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\FailedEffectSettler;
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
 * Invariant 5: a dead-lettered command means no effect committed; settle on the spot with the
 * POSITIONAL eligibility. The dead-lettered step itself is unconfirmed, so skipped; earlier confirmed
 * steps roll back, the credit-leg failure refunds the debit.
 */
final class FailedEffectSettlerTest extends TestCase
{
    #[Test]
    public function it_refunds_the_earlier_confirmed_step_and_skips_the_dead_lettered_one(): void
    {
        $row = $this->row([
            CompensationRecord::pending('hold')->confirm(),  // the debit, its effect is confirmed
            CompensationRecord::pending('credit'),           // the dead-lettered leg, no effect committed
        ]);

        $run = new FailedEffectSettler(new Compensator(new MutableClock))->settle($this->def(), $row, null);

        $this->assertSame(WorkflowStatus::Compensated, $run->row->status);
        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status); // refunded
        $this->assertSame(CompensationStatus::Skipped, $run->row->compensations[1]->status);     // nothing to undo
        $this->assertSame('unconfirmed', $run->row->compensations[1]->reason);
        $this->assertCount(1, array_filter($run->announcements, static fn (object $e): bool => $e instanceof SagaCompensated));
    }

    #[Test]
    public function an_untracked_earlier_step_is_undone_positionally(): void
    {
        // the settle is POSITIONAL: progression past `fee`, untracked, implies its effect happened;
        // it rolls back too, where a location-agnostic settle would have skipped it as unverifiable
        $row = $this->row([
            CompensationRecord::pending('fee'),     // untracked, no confirmedBy
            CompensationRecord::pending('credit'),  // the dead-lettered leg, tracked, unconfirmed
        ]);

        $run = new FailedEffectSettler(new Compensator(new MutableClock))->settle($this->def(), $row, null);

        $this->assertSame(CompensationStatus::Compensated, $run->row->compensations[0]->status);
        $this->assertSame(CompensationStatus::Skipped, $run->row->compensations[1]->status);
    }

    #[Test]
    public function with_nothing_to_undo_it_halts_on_the_spot(): void
    {
        $run = new FailedEffectSettler(new Compensator(new MutableClock))->settle($this->def(), $this->row([]), null);

        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertSame('await_credit', $run->row->stateKey); // frozen where it sat
    }

    private function def(): WorkflowDefinition
    {
        $undo = static fn (): RecordingActivity => new RecordingActivity(ActivityResult::success());

        $states = [
            'fee' => new ActivityState('fee', $undo(), compensation: $undo(), transitions: [new Transition(OnTrigger::Success, 'hold')]),
            'hold' => new ActivityState('hold', $undo(), compensation: $undo(), compensationConfirmedBy: stdClass::class, transitions: [new Transition(OnTrigger::Success, 'await_hold')]),
            'await_hold' => new WaitState('await_hold', eventClasses: [stdClass::class], transitions: [new Transition(OnTrigger::Event, 'credit')]),
            'credit' => new ActivityState('credit', $undo(), compensation: $undo(), compensationConfirmedBy: SampleEvent::class, transitions: [new Transition(OnTrigger::Success, 'await_credit')]),
            'await_credit' => new WaitState('await_credit', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
        ];

        return new WorkflowDefinition('transfer', $states, 'hold');
    }

    /**
     * @param  list<CompensationRecord>  $log
     */
    private function row(array $log): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('transfer', 'c-1', 'await_credit', WorkflowStatus::Running, [], [], [], 0, new PointInTime, $log);
    }
}
