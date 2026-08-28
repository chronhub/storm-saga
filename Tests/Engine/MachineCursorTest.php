<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\MachineCursor;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Engine\Verdict\Finalize;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Noop;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Event\SagaCompleted;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaRetried;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The cursor's two transformations, pinned directly with MULTI-item lists. Every collected list,
 * ops, commands and announcements, must accumulate completely and in order across crossings and into
 * the rest; a runner only ever emits one op/command at a time, so these merge contracts are only
 * reachable here.
 */
final class MachineCursorTest extends TestCase
{
    #[Test]
    public function crossings_accumulate_commands_ops_and_announcements_in_order(): void
    {
        $c1 = new stdClass;
        $c2 = new stdClass;

        $cursor = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success, ['v' => 1], [$c1]), [], true)
            ->transition(new Transition('c', OnTrigger::Success, ['v' => 2], [$c2]), [], true);

        $run = $cursor->rest(new Noop);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame('c', $run->row->stateKey);
        $this->assertSame(['v' => 2], $run->row->vars);
        // both crossings' commands, in order; each wrapped with the state whose verdict yielded it
        $this->assertSame([['a', $c1], ['b', $c2]], array_map(static fn ($ic): array => [$ic->fromState, $ic->command], $run->commands));

        $kinds = array_map(static fn (TimerOp $op): array => [$op->kind, $op->stateKey], $run->timerOps);
        $this->assertSame([[TimerOpKind::CancelState, 'a'], [TimerOpKind::CancelState, 'b']], $kinds);

        $this->assertCount(2, $run->announcements);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[0]);
        $this->assertSame('a', $run->announcements[0]->from);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[1]);
        $this->assertSame('b', $run->announcements[1]->from);
    }

    #[Test]
    public function a_transition_told_not_to_cancel_omits_the_cancel_op(): void
    {
        // the runner passes false when the left state can hold no armed timer, such as a synchronous intermediate
        // or an untimed wait: the no-op cancelState DELETE is skipped, not emitted.
        $run = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success), [], false)
            ->rest(new Noop);

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame([], $run->timerOps); // no cancelState for a state that never armed one
    }

    #[Test]
    public function a_stay_merges_its_own_ops_commands_and_retries_after_the_accumulated_ones(): void
    {
        $prior = new stdClass;
        $issued = new stdClass;
        $op1 = TimerOp::armTimeout('b', 10);
        $op2 = TimerOp::armKick('b', 500);

        $run = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success, [], [$prior]), [], true)
            ->rest(new Stay([$op1, $op2], ['v' => 9], ['b' => ['n' => 3, 'since' => null]], [$issued]));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(['v' => 9], $run->row->vars);           // the Stay's vars are authoritative
        $this->assertSame(['b' => ['n' => 3, 'since' => null]], $run->row->retries); // the Stay's ledgers win when carried
        $this->assertSame([['a', $prior], ['b', $issued]], array_map(static fn ($ic): array => [$ic->fromState, $ic->command], $run->commands));
        $this->assertSame([TimerOpKind::CancelState, TimerOpKind::ArmTimeout, TimerOpKind::ArmKick], array_map(static fn (TimerOp $op) => $op->kind, $run->timerOps));
    }

    #[Test]
    public function a_stay_without_counters_keeps_the_accumulated_ones(): void
    {
        $run = MachineCursor::at($this->row('a', retries: ['other' => ['n' => 2, 'since' => null]]))
            ->rest(new Stay([], [], null));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(['other' => ['n' => 2, 'since' => null]], $run->row->retries);
    }

    #[Test]
    public function a_crossing_resets_the_retime_budget_and_a_rest_in_place_keeps_it(): void
    {
        // The budget is per VISIT of a wait, so what spends it is negotiating THIS deadline. Crossing
        // anywhere ends that negotiation and opens a new one; staying put, a retry or an async stay,
        // is the same visit still. Swap the two and a saga either negotiates forever or loses a
        // budget it never used.
        $parked = new WorkflowInstanceRow('wf', 'c-1', 'a', WorkflowStatus::Running, [], [], [], 0, new PointInTime, retimes: 2);

        $stayed = MachineCursor::at($parked)->rest(new Stay([], [], null));
        $this->assertInstanceOf(Rested::class, $stayed);
        $this->assertSame(2, $stayed->row->retimes);

        $crossed = MachineCursor::at($parked)
            ->transition(new Transition('b', OnTrigger::Success, [], []), [], true)
            ->rest(new Noop);
        $this->assertInstanceOf(Rested::class, $crossed);
        $this->assertSame(0, $crossed->row->retimes);
    }

    #[Test]
    public function a_retry_stay_announces_saga_retried_with_attempt_and_total(): void
    {
        $run = MachineCursor::at($this->row('b'))
            ->rest(new Stay([TimerOp::armKick('b', 500)], ['v' => 1], ['b' => ['n' => 2, 'since' => null]], retryTotal: 5));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(WorkflowStatus::Running, $run->row->status);

        $this->assertCount(1, $run->announcements); // the retry is the only history trace; the Stay is otherwise silent
        $this->assertInstanceOf(SagaRetried::class, $run->announcements[0]);
        $this->assertSame('wf', $run->announcements[0]->workflowType);
        $this->assertSame('c-1', $run->announcements[0]->correlationId);
        $this->assertSame('b', $run->announcements[0]->stateKey);
        $this->assertSame(2, $run->announcements[0]->attempt);    // the per-state attempt count carried by the Stay
        $this->assertSame(5, $run->announcements[0]->retryTotal); // the instance's lifetime total
    }

    #[Test]
    public function a_retry_stay_with_no_recorded_state_attempt_announces_attempt_zero(): void
    {
        // the defensive `?? 0`: a retry Stay carrying a retryTotal but no per-state counter for this key
        // announces attempt 0; pins the fallback against a mutated 1/-1
        $run = MachineCursor::at($this->row('b'))
            ->rest(new Stay([TimerOp::armKick('b', 500)], ['v' => 1], [], retryTotal: 3));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertInstanceOf(SagaRetried::class, $run->announcements[0]);
        $this->assertSame(0, $run->announcements[0]->attempt);
        $this->assertSame(3, $run->announcements[0]->retryTotal);
    }

    #[Test]
    public function a_retry_after_a_crossing_keeps_the_earlier_announcements(): void
    {
        // a Stay that retries AFTER a synchronous crossing keeps the crossing's SagaTransitioned ahead of its
        // own SagaRetried; pins the `[...$this->announcements, …]` spread, not just the appended event
        $run = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success), [], true)
            ->rest(new Stay([TimerOp::armKick('b', 100)], ['v' => 1], ['b' => ['n' => 1, 'since' => null]], retryTotal: 2));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertCount(2, $run->announcements);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[0]); // the crossing survived
        $this->assertInstanceOf(SagaRetried::class, $run->announcements[1]);
    }

    #[Test]
    public function a_non_retry_stay_announces_nothing(): void
    {
        $run = MachineCursor::at($this->row('b'))
            ->rest(new Stay([], ['v' => 1]));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertCount(0, $run->announcements); // a plain re-arm with no retryTotal stays silent
    }

    #[Test]
    public function finalize_appends_completion_after_the_crossing_announcements(): void
    {
        $run = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success), [], true)
            ->transition(new Transition('done', OnTrigger::Success), [], true)
            ->rest(new Finalize(['v' => 1]));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(WorkflowStatus::Completed, $run->row->status);
        $this->assertSame(['v' => 1], $run->row->vars);

        $this->assertCount(3, $run->announcements); // the two hops, then the completion, full and in order
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[1]);
        $this->assertInstanceOf(SagaCompleted::class, $run->announcements[2]);
    }

    #[Test]
    public function a_bare_halt_appends_its_announcement_and_its_commands_after_the_accumulated(): void
    {
        $prior = new stdClass;
        $lastWords = new stdClass;

        $run = MachineCursor::at($this->row('a'))
            ->transition(new Transition('b', OnTrigger::Success, ['v' => 1], [$prior]), [], true)
            ->rest(new Halt([$lastWords]));

        $this->assertInstanceOf(Rested::class, $run);
        $this->assertSame(WorkflowStatus::Halted, $run->row->status);
        $this->assertSame(['v' => 1], $run->row->vars); // accumulated vars; a halt has no authoritative ones
        $this->assertSame([['a', $prior], ['b', $lastWords]], array_map(static fn ($ic): array => [$ic->fromState, $ic->command], $run->commands));

        $this->assertCount(2, $run->announcements);
        $this->assertInstanceOf(SagaTransitioned::class, $run->announcements[0]);
        $this->assertInstanceOf(SagaHalted::class, $run->announcements[1]);
    }

    /**
     * @param  array<string, array{n: int, since: string|null}>  $retries
     */
    private function row(string $stateKey, array $retries = []): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('wf', 'c-1', $stateKey, WorkflowStatus::Running, [], $retries, [], 0, new PointInTime);
    }
}
