<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\SampleEvent;

final class WorkflowInstanceRowTest extends TestCase
{
    #[Test]
    public function a_fresh_row_starts_at_version_zero(): void
    {
        // the OCC anchor: a created row must enter the store at version 0, the first update expects it
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running);

        $this->assertSame(0, $row->version);
        $this->assertSame(1, $row->definitionVersion); // defaults: born under definition v1 …
        $this->assertSame(0, $row->retryTotal);        // … with no lifetime retries spent yet
    }

    #[Test]
    public function resting_carries_the_retry_total_forward_unless_a_new_one_is_given(): void
    {
        // restingAt(retryTotal: null) keeps the running lifetime total; a non-null value replaces it via the `?? `
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, retryTotal: 5);

        $this->assertSame(5, $row->restingAt('await', WorkflowStatus::Running, [], [], [])->retryTotal);    // null carries 5
        $this->assertSame(9, $row->restingAt('await', WorkflowStatus::Running, [], [], [], 9)->retryTotal); // 9 replaces
    }

    #[Test]
    public function only_an_aborting_settle_reads_as_aborted(): void
    {
        // the settle's recall gate: an abort, halted or rolled back, mows the still-pending outbox
        // rows; a COMPLETED flow never does: its pending commands may be legitimate fire-and-forget,
        // and an app-level "failed" final state is still a completion; nor does a running saga
        $this->assertTrue(new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Halted)->aborted());
        $this->assertTrue(new WorkflowInstanceRow('payment', 'o-2', 'charge', WorkflowStatus::Compensated)->aborted());
        $this->assertFalse(new WorkflowInstanceRow('payment', 'o-3', 'failed', WorkflowStatus::Completed)->aborted());
        $this->assertFalse(new WorkflowInstanceRow('payment', 'o-4', 'book', WorkflowStatus::Running)->aborted());
    }

    #[Test]
    public function its_identity_is_the_workflow_type_and_correlation_pair(): void
    {
        $id = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running)->id();

        $this->assertSame('payment', $id->workflowType);
        $this->assertSame('o-1', $id->correlationId);
    }

    #[Test]
    public function no_mover_can_touch_the_occ_and_deadline_anchors(): void
    {
        // version, startedAt and context are untouchable by ANY mover; the footgun, dead by type
        $startedAt = new PointInTime;
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, ['a' => 1], ['charge' => ['n' => 2, 'since' => null]], ['actor' => 'cli'], 7, $startedAt);

        foreach ([
            'restingAt' => $row->restingAt('await', WorkflowStatus::Running, ['b' => 2], [], []),
            'halted' => $row->halted(),
            'forcedTo' => $row->forcedTo('failed'),
            'settled' => $row->settled(WorkflowStatus::Compensated, ['c' => 3], []),
        ] as $mover => $moved) {
            $this->assertSame(7, $moved->version, $mover);
            $this->assertSame($startedAt, $moved->startedAt, $mover);
            $this->assertSame(['actor' => 'cli'], $moved->context, $mover);
        }
    }

    #[Test]
    public function every_step_mover_threads_the_state_version_untouched(): void
    {
        // the third axis: only the state migrator may move it, never a step mover
        $row = WorkflowInstanceRow::fresh(new WorkflowId('payment', 'o-1'), 'charge', [], [], new PointInTime, 1, 3);

        foreach ([
            'restingAt' => $row->restingAt('await', WorkflowStatus::Running, [], [], []),
            'halted' => $row->halted(),
            'forcedTo' => $row->forcedTo('failed'),
            'settled' => $row->settled(WorkflowStatus::Compensated, [], []),
            'waived' => $row->waived(new PointInTime),
            'claimedAs' => $row->claimedAs(2),
        ] as $mover => $moved) {
            $this->assertSame(3, $moved->stateVersion, $mover);
        }
    }

    #[Test]
    public function the_migration_is_the_one_mover_of_the_state_version(): void
    {
        $row = WorkflowInstanceRow::fresh(new WorkflowId('payment', 'o-1'), 'charge', ['a' => 1], [], new PointInTime, 1, 2);

        $moved = $row->migratedTo(3, ['b' => 2]);

        $this->assertSame(3, $moved->stateVersion);
        $this->assertSame(['b' => 2], $moved->vars);
        $this->assertSame('charge', $moved->stateKey);      // nothing else moves
        $this->assertSame($row->version, $moved->version);  // the OCC anchor is the store's to bump
    }

    #[Test]
    public function a_fresh_row_defaults_its_state_version_to_one(): void
    {
        $row = WorkflowInstanceRow::fresh(new WorkflowId('payment', 'o-1'), 'charge', [], [], new PointInTime, 1);

        $this->assertSame(1, $row->stateVersion);

        // `fresh()` passes it, so only a direct construction reads the CONSTRUCTOR's own default,
        // which is what a hydration from a row predating the field falls back to
        $bare = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running);

        $this->assertSame(1, $bare->stateVersion);
        $this->assertSame(0, $bare->retimes);
    }

    #[Test]
    public function each_mover_carries_its_own_semantics(): void
    {
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, ['a' => 1], ['charge' => ['n' => 2, 'since' => null]]);

        $resting = $row->restingAt('await', WorkflowStatus::Completed, ['b' => 2], ['x' => ['n' => 1, 'since' => null]], []);
        $this->assertSame('await', $resting->stateKey);
        $this->assertSame(WorkflowStatus::Completed, $resting->status);
        $this->assertSame(['b' => 2], $resting->vars);
        $this->assertSame(['x' => ['n' => 1, 'since' => null]], $resting->retries);

        $halted = $row->halted();
        $this->assertSame(WorkflowStatus::Halted, $halted->status);
        $this->assertSame('charge', $halted->stateKey);   // frozen on the spot
        $this->assertSame(['a' => 1], $halted->vars);     // everything preserved

        $forced = $row->forcedTo('failed');
        $this->assertSame('failed', $forced->stateKey);
        $this->assertSame(WorkflowStatus::Running, $forced->status); // still running; the forced state executes

        $settled = $row->settled(WorkflowStatus::Compensated, ['v' => 9], []);
        $this->assertSame(WorkflowStatus::Compensated, $settled->status);
        $this->assertSame(['v' => 9], $settled->vars);    // the vars the undos threaded
        $this->assertSame('charge', $settled->stateKey);  // settles where it halted
    }

    #[Test]
    public function the_retime_budget_is_per_resting_spell(): void
    {
        // two retimes spent while parked on `await`; resting on a NEW state ends that deadline's
        // negotiation and the counter resets, while a rest in place, a signal or a retry, keeps it.
        // The maxAttempts-per-visit philosophy, not retry_total's lifetime one
        $row = new WorkflowInstanceRow('payment', 'o-1', 'await', WorkflowStatus::Running);
        $spent = $row->retimed()->retimed();
        $this->assertSame(2, $spent->retimes);

        $inPlace = $spent->restingAt('await', WorkflowStatus::Running, [], [], []);
        $this->assertSame(2, $inPlace->retimes); // restingAt threads it: the CURSOR owns the reset, on advanced rests

        $reset = $spent->retimesReset();
        $this->assertSame(0, $reset->retimes); // the crossing's mover: a new spell negotiates afresh

        $forced = $spent->forcedTo('failed');
        $this->assertSame(0, $forced->retimes); // a forced exit ends the negotiation like any crossing
    }

    #[Test]
    public function a_parked_crossing_is_sealed_to_the_wait_it_was_taken_at(): void
    {
        $row = new WorkflowInstanceRow('payment', 'o-1', 'await', WorkflowStatus::Running);

        $parked = $row->parkedAt(SampleEvent::class, 'cause-1');

        $this->assertSame(['state' => 'await', 'event' => SampleEvent::class, 'cause' => 'cause-1'], $parked->parked);
        $this->assertNull($row->parked); // the original is untouched, as every mover leaves it
    }

    #[Test]
    public function a_park_survives_a_rest_in_place_and_dies_on_a_crossing(): void
    {
        // the park belongs to its wait: a rest in place is the gate absorbing another conclusion, so
        // it must survive, while crossing ANYWHERE else discharges it. Without that, a graph that
        // returns to the same wait later could replay a previous visit's owed crossing.
        $parked = new WorkflowInstanceRow('payment', 'o-1', 'await', WorkflowStatus::Running)->parkedAt(SampleEvent::class, null);

        $inPlace = $parked->restingAt('await', WorkflowStatus::Running, [], [], []);
        $this->assertSame($parked->parked, $inPlace->parked);

        $crossed = $parked->restingAt('done', WorkflowStatus::Completed, [], [], []);
        $this->assertNull($crossed->parked);

        $forced = $parked->forcedTo('failed');
        $this->assertNull($forced->parked); // a forced exit ends it like any crossing
    }

    #[Test]
    public function spending_a_parked_crossing_discharges_it(): void
    {
        // discharged before the machine runs, so a crossing that lands back on this same wait, a
        // self-loop, cannot leave the next poke a second claim on it
        $parked = new WorkflowInstanceRow('payment', 'o-1', 'await', WorkflowStatus::Running)->parkedAt(SampleEvent::class, null);

        $this->assertNull($parked->unparked()->parked);
    }

    #[Test]
    public function a_fresh_row_is_born_running_at_the_start_state(): void
    {
        $startedAt = new PointInTime;
        $row = WorkflowInstanceRow::fresh(new WorkflowId('payment', 'o-1'), 'charge', ['a' => 1], ['actor' => 'cli'], $startedAt, 2);

        $this->assertSame(WorkflowStatus::Running, $row->status);
        $this->assertSame('charge', $row->stateKey);
        $this->assertSame(0, $row->version);            // OCC counter starts at 0
        $this->assertSame(2, $row->definitionVersion);  // pinned to the version it was born under
        $this->assertSame([], $row->retries);
        $this->assertSame([], $row->compensations);
        $this->assertSame($startedAt, $row->startedAt);
    }

    #[Test]
    public function a_row_built_without_a_generation_is_the_first_run(): void
    {
        // the constructor default is load-bearing: a row hydrated from a pre-run-identity install, or
        // built by a caller that predates the column, must read as run 1 rather than 0 or 2; an
        // off-by-one here would seal that instance's commands to a generation it never had.
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running);

        $this->assertSame(1, $row->generation);
    }

    #[Test]
    public function a_fresh_row_starts_at_the_first_run_and_takes_no_generation(): void
    {
        // `fresh()` deliberately has no generation parameter: the run number does not exist until the
        // claim is inserted, so the store hands it back and `claimedAs()` stamps it.
        $fresh = WorkflowInstanceRow::fresh(new WorkflowId('payment', 'o-1'), 'charge', [], [], new PointInTime, 3);

        $this->assertSame(1, $fresh->generation);
        $this->assertSame(4, $fresh->claimedAs(4)->generation);
        $this->assertSame(3, $fresh->claimedAs(4)->definitionVersion, 'the stamp touches nothing else');
    }
}
