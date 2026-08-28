<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Schedule;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Engine\SagaTimerTarget;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Saga\Store\DueTimerQueue;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowTimerRow;

/**
 * The attempt budget that turns a crash-looping timer into a quarantined one.
 *
 * The cap is the runner's own default, wired by nothing, so it IS the production value. Both sides of
 * it are asserted: one attempt short, the failure is transient and the throw carries the retry; ON the
 * cap, the row is parked and the batch keeps going. A cap off by one either parks a timer that still
 * had budget, or lets a poison row keep taking the daemon down.
 */
final class TimerAttemptBudgetTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function a_failure_within_budget_is_recorded_and_the_batch_continues(): void
    {
        // the bounded allowance: one flaky row is recorded and SKIPPED, still claimed for the
        // lease's retry, instead of holding its co-claimed siblings for the whole window
        $queue = $this->queue(recordedAttempts: 4);
        $queue->expects($this->never())->method('park');
        $queue->expects($this->once())->method('recordFailure');

        $this->assertSame(1, $this->runner($queue)->tick()->processed, 'the batch completes; the lease owns the retry');
    }

    #[Test]
    #[Group('adversarial')]
    public function the_batch_bails_once_the_transient_allowance_is_spent(): void
    {
        // past the allowance a shared cause is the likelier read, a blinking database, where
        // skipping on would only fail every remaining row too: the fourth transient propagates
        // and the fifth row is never attempted
        $rows = array_map(fn (int $id): WorkflowTimerRow => $this->row(TimerKind::Timeout, $id), [1, 2, 3, 4, 5]);

        $queue = $this->createMock(DueTimerQueue::class);
        $queue->method('claimDue')->willReturn($rows);
        $queue->method('recordFailure')->willReturn(1);
        $queue->expects($this->never())->method('park');

        $engine = $this->createMock(SagaTimerTarget::class);
        $engine->expects($this->exactly(4))->method('timeout')->willThrowException(new RuntimeException('the activity blew up'));

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

        $this->expectException(RuntimeException::class);

        new TimerRunner($queue, $engine, $clock)->tick();
    }

    #[Test]
    public function a_flaky_row_does_not_starve_its_co_claimed_siblings(): void
    {
        // the collateral the allowance exists to remove: without it, one transient held the other
        // claimed timers for the full lease and could only spend its budget once per window
        $rows = [$this->row(TimerKind::Timeout, 1), $this->row(TimerKind::Kick, 2)];

        $queue = $this->createStub(DueTimerQueue::class);
        $queue->method('claimDue')->willReturn($rows);
        $queue->method('recordFailure')->willReturn(1);

        $engine = $this->createMock(SagaTimerTarget::class);
        $engine->expects($this->once())->method('timeout')->willThrowException(new RuntimeException('flaky'));
        $engine->expects($this->once())->method('kick');

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

        $this->assertSame(2, new TimerRunner($queue, $engine, $clock)->tick()->processed, 'the sibling was served despite the earlier transient');
    }

    #[Test]
    #[Group('adversarial')]
    public function the_failure_that_reaches_the_cap_parks_the_row_instead_of_crashing_the_daemon(): void
    {
        $queue = $this->queue(recordedAttempts: 5);
        $queue->expects($this->once())->method('park')->with(1);

        $this->assertSame(1, $this->runner($queue)->tick()->processed, 'the batch is still reported as claimed');
    }

    #[Test]
    public function the_batch_and_the_lease_reach_the_claim_as_given(): void
    {
        // the claim's window IS the runner's contract with the store: a batch or a lease that arrives
        // changed reads rows nobody asked for, or re-claims a row another runner still holds
        $queue = $this->createMock(DueTimerQueue::class);
        $queue->expects($this->once())->method('claimDue')
            ->with(100, $this->anything(), 300)
            ->willReturn([]);

        $this->assertSame(0, $this->runner($queue)->tick()->processed);
    }

    #[Test]
    public function each_kind_of_timer_reaches_the_verb_that_belongs_to_it(): void
    {
        // one arm per kind, and they are not interchangeable: a timeout that kicks would advance a
        // saga the deadline was meant to end. All FOUR: the match has no default, so an arm this list
        // skips is an arm whose removal turns its kind into an unhandled-match crash unnoticed.
        foreach ([[TimerKind::Timeout, 'timeout'], [TimerKind::Kick, 'kick'], [TimerKind::Global, 'globalTimeout'], [TimerKind::Schedule, 'schedule']] as [$kind, $verb]) {
            $queue = $this->createStub(DueTimerQueue::class);
            $queue->method('claimDue')->willReturn([$this->row($kind)]);

            $engine = $this->createMock(SagaTimerTarget::class);
            $engine->expects($this->once())->method($verb);

            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

            new TimerRunner($queue, $engine, $clock)->tick();
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function a_timer_orphaned_by_a_purge_is_parked_without_spending_a_single_attempt(): void
    {
        // permanent, not transient: this row can NEVER drive, so burning the budget five times over
        // would only delay the quarantine and keep the batch busy
        foreach ([WorkflowNotFound::class, WorkflowVersionNotFound::class] as $permanent) {
            $queue = $this->createMock(DueTimerQueue::class);
            $queue->method('claimDue')->willReturn([$this->row(TimerKind::Timeout)]);
            $queue->expects($this->never())->method('recordFailure');
            $queue->expects($this->once())->method('park')->with(1);

            $engine = $this->createStub(SagaTimerTarget::class);
            $engine->method('timeout')->willThrowException(new $permanent('gone'));

            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

            $this->assertSame(1, new TimerRunner($queue, $engine, $clock)->tick()->processed);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_orphaned_row_does_not_end_the_batch_it_opens(): void
    {
        // parking an orphan is a SKIP, not a stop. A purge leaves its orphans at the front of a
        // claim as readily as at its end, and a batch ending on the first of them would leave every
        // healthy timer behind it unfired until the next tick.
        $queue = $this->createMock(DueTimerQueue::class);
        $queue->method('claimDue')->willReturn([$this->row(TimerKind::Timeout, 1), $this->row(TimerKind::Kick, 2)]);
        $queue->expects($this->once())->method('park')->with(1);

        $engine = $this->createMock(SagaTimerTarget::class);
        $engine->method('timeout')->willThrowException(new WorkflowNotFound('gone'));
        $engine->expects($this->once())->method('kick');

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

        $this->assertSame(2, new TimerRunner($queue, $engine, $clock)->tick()->processed);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_quarantined_row_does_not_end_the_batch_it_opens(): void
    {
        // the twin of the orphan skip, on the other park: a row whose budget is spent is quarantined
        // and the batch keeps going, or one poisoned timer would hold every sibling it was claimed
        // with for the whole lease window
        $queue = $this->createMock(DueTimerQueue::class);
        $queue->method('claimDue')->willReturn([$this->row(TimerKind::Timeout, 1), $this->row(TimerKind::Kick, 2)]);
        $queue->method('recordFailure')->willReturn(5);
        $queue->expects($this->once())->method('park')->with(1);

        $engine = $this->createMock(SagaTimerTarget::class);
        $engine->method('timeout')->willThrowException(new RuntimeException('the activity blew up'));
        $engine->expects($this->once())->method('kick');

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

        $this->assertSame(2, new TimerRunner($queue, $engine, $clock)->tick()->processed);
    }

    #[Test]
    public function the_tick_reports_a_full_claim_as_more_due_and_a_short_one_as_not(): void
    {
        // the drain reads this flag to know whether the cap cut the pass: a full claim means more is
        // due, and a runner trusting an inverted flag would stop with work still on the table, or spin
        // on an empty one
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));
        $engine = $this->createStub(SagaTimerTarget::class);

        $full = $this->createStub(DueTimerQueue::class);
        $full->method('claimDue')->willReturn([$this->row(TimerKind::Timeout, 1), $this->row(TimerKind::Kick, 2)]);
        $this->assertTrue(new TimerRunner($full, $engine, $clock)->tick(batch: 2)->moreDue);

        $short = $this->createStub(DueTimerQueue::class);
        $short->method('claimDue')->willReturn([$this->row(TimerKind::Timeout, 1)]);
        $this->assertFalse(new TimerRunner($short, $engine, $clock)->tick(batch: 2)->moreDue);
    }

    // rig

    private function row(TimerKind $kind, int $id = 1): WorkflowTimerRow
    {
        return new WorkflowTimerRow(
            id: $id,
            workflowType: 'transfer',
            correlationId: 'c-'.$id,
            stateKey: 'await_capture',
            kind: $kind,
            fireAt: PointInTime::from('2026-01-01T10:00:00.000000+00:00'),
        );
    }

    private function queue(int $recordedAttempts): DueTimerQueue&MockObject
    {
        $queue = $this->createMock(DueTimerQueue::class);
        $queue->method('claimDue')->willReturn([$this->row(TimerKind::Timeout)]);
        $queue->method('recordFailure')->willReturn($recordedAttempts);

        return $queue;
    }

    private function runner(DueTimerQueue $queue): TimerRunner
    {
        $engine = $this->createStub(SagaTimerTarget::class);
        $engine->method('timeout')->willThrowException(new RuntimeException('the activity blew up'));

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from('2026-01-01T10:00:00.000000+00:00'));

        return new TimerRunner($queue, $engine, $clock);
    }
}
