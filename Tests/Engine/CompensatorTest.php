<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Event\CompensationFailed;
use Storm\Saga\Event\SagaCompensated;
use Storm\Saga\Event\SagaCompensationSkipped;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementContract;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Tests\Fixture\ThrowingActivity;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationMode;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\JoinArmSlot;
use Storm\Saga\Workflow\JoinPolicy;
use Storm\Saga\Workflow\RaceArmSlot;
use Storm\Saga\Workflow\RacePolicy;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Direct unit coverage of the compensation-log seams, which stand alone.
 * The full rollback behaviour is exercised end-to-end by the integration CompensationTest; here we pin
 * the collaborator's own contract, mutation-grade:
 * - Eligibility per mode
 * - Skip reasons
 * - Undo order
 * - Command accumulation
 * - The audit events.
 *
 * Fixture states:
 * - `charge`: undo, confirmedBy stdClass
 * - `ship`: undo, confirmedBy SampleEvent
 * - `audit`: NO compensation
 * - `refund`: undo, untracked, no confirmedBy
 */
final class CompensatorTest extends TestCase
{
    #[Test]
    public function maybe_compensate_is_a_pass_through_when_the_result_is_not_halted(): void
    {
        $running = new Rested($this->row(WorkflowStatus::Running, []));

        $this->assertSame($running, $this->compensator()->maybeCompensate($this->def(), $running, null));
    }

    #[Test]
    public function maybe_compensate_is_a_pass_through_when_there_is_nothing_to_roll_back(): void
    {
        $halted = new Rested($this->row(WorkflowStatus::Halted, []));

        $this->assertSame($halted, $this->compensator()->maybeCompensate($this->def(), $halted, null));
    }

    #[Test]
    public function maybe_compensate_rolls_back_a_halted_result_with_a_log(): void
    {
        $halted = new Rested($this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')->confirm()]));

        $result = $this->compensator()->maybeCompensate($this->def(), $halted, null);

        $this->assertNotSame($halted, $result);
        $this->assertSame(WorkflowStatus::Compensated, $result->row->status);
    }

    #[Test]
    public function step_keys_lists_the_logged_steps_in_completion_order(): void
    {
        $keys = $this->compensator()->stepKeys([CompensationRecord::pending('charge'), CompensationRecord::pending('ship')]);

        $this->assertSame(['charge', 'ship'], $keys);
    }

    #[Test]
    public function advancing_on_a_success_leave_of_a_compensatable_step_logs_it_pending(): void
    {
        $def = $this->def();
        $verdict = new Transition('await', OnTrigger::Success);

        $log = $this->compensator()->advanceLog($def, $def->state('charge'), $verdict, null, [], new PointInTime);

        $this->assertCount(1, $log);
        $this->assertSame('charge', $log[0]->step);
        $this->assertSame(CompensationStatus::Pending, $log[0]->status);
        $this->assertFalse($log[0]->confirmed);
        $this->assertFalse($log[0]->degraded);
    }

    #[Test]
    public function the_accrual_appends_after_the_existing_log(): void
    {
        $def = $this->def();
        $verdict = new Transition('await', OnTrigger::Success);

        $log = $this->compensator()->advanceLog($def, $def->state('charge'), $verdict, null, [CompensationRecord::pending('prior')], new PointInTime);

        $this->assertCount(2, $log);
        $this->assertSame('prior', $log[0]->step);
        $this->assertSame('charge', $log[1]->step);
    }

    #[Test]
    public function a_success_leave_never_confirms_even_with_an_event_in_hand(): void
    {
        // only an Event-triggered crossing confirms; a success hop that happens to carry the step's
        // confirming event, an event stimulus landing on an activity state, must not flip the record
        $def = $this->def();
        $verdict = new Transition('await', OnTrigger::Success);

        $log = $this->compensator()->advanceLog($def, $def->state('audit'), $verdict, stdClass::class, [CompensationRecord::pending('charge')], new PointInTime);

        $this->assertFalse($log[0]->confirmed);
    }

    #[Test]
    public function confirmation_returns_the_whole_log_not_just_the_flipped_record(): void
    {
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Event);

        $log = $this->compensator()->advanceLog($def, $def->state('await_x'), $verdict, stdClass::class, [
            CompensationRecord::pending('charge'),
            CompensationRecord::pending('refund'),
        ], new PointInTime);

        $this->assertCount(2, $log);
        $this->assertTrue($log[0]->confirmed);   // stdClass confirms charge
        $this->assertFalse($log[1]->confirmed);  // refund is untracked, untouched
    }

    #[Test]
    public function an_already_confirmed_record_is_never_re_confirmed(): void
    {
        // Confirmation is once and for all, and the guard says so as a DISJUNCTION: confirmed, OR
        // no longer pending. Read as a conjunction, a record that is confirmed while still pending,
        // which is exactly what a first confirmation leaves behind, would be walked again and
        // re-stamped by the next matching event.
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Event);
        $already = CompensationRecord::pending('charge')->confirm();

        $log = $this->compensator()->advanceLog($def, $def->state('await_x'), $verdict, stdClass::class, [$already], new PointInTime);

        $this->assertSame($already, $log[0]);
    }

    #[Test]
    public function a_degraded_salvage_is_logged_degraded(): void
    {
        $def = $this->def();
        $verdict = new Transition('await', OnTrigger::Success, degraded: true);

        $log = $this->compensator()->advanceLog($def, $def->state('charge'), $verdict, null, [], new PointInTime);

        $this->assertTrue($log[0]->degraded);
    }

    #[Test]
    public function leaving_a_step_without_a_compensation_logs_nothing(): void
    {
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Success);

        $this->assertSame([], $this->compensator()->advanceLog($def, $def->state('audit'), $verdict, null, [], new PointInTime));
    }

    #[Test]
    public function a_failure_leave_logs_nothing_even_on_a_compensatable_step(): void
    {
        // only a Success leave means "the effect happened"; a Failure leave has nothing to undo
        $def = $this->def();
        $verdict = new Transition('abort', OnTrigger::Failure);

        $this->assertSame([], $this->compensator()->advanceLog($def, $def->state('charge'), $verdict, null, [], new PointInTime));
    }

    #[Test]
    public function an_event_triggered_advance_confirms_the_step_the_event_names(): void
    {
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Event);

        $log = $this->compensator()->advanceLog($def, $def->state('await_x'), $verdict, stdClass::class, [CompensationRecord::pending('charge')], new PointInTime);

        $this->assertTrue($log[0]->confirmed);
    }

    #[Test]
    public function an_event_that_confirms_nothing_leaves_the_log_untouched(): void
    {
        // SampleEvent confirms `ship`, not `charge`; a non-matching event must not flip the record
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Event);

        $log = $this->compensator()->advanceLog($def, $def->state('await_x'), $verdict, SampleEvent::class, [CompensationRecord::pending('charge')], new PointInTime);

        $this->assertFalse($log[0]->confirmed);
    }

    #[Test]
    public function confirmation_matches_a_confirming_event_by_subtype_not_only_the_exact_class(): void
    {
        // `settle`'s confirmedBy is the SettlementContract interface; a concrete event implementing it must
        // confirm the step, mirroring the wait's `instanceof` match. An exact-class === check
        // would never flip it, and a later location-agnostic rollback would then skip a real, committed effect.
        $def = $this->def();
        $verdict = new Transition('next', OnTrigger::Event);

        $log = $this->compensator()->advanceLog($def, $def->state('await_x'), $verdict, SettlementSettled::class, [CompensationRecord::pending('settle')], new PointInTime);

        $this->assertTrue($log[0]->confirmed);
    }

    #[Test]
    public function has_confirmed_compensation_is_true_only_for_a_confirmed_compensatable_step(): void
    {
        $compensator = $this->compensator();
        $def = $this->def();

        $unconfirmed = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')]);
        $confirmed = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')->confirm()]);

        $this->assertFalse($compensator->hasConfirmedCompensation($def, $unconfirmed));
        $this->assertTrue($compensator->hasConfirmedCompensation($def, $confirmed));
    }

    #[Test]
    public function has_confirmed_compensation_ignores_a_step_without_a_compensation(): void
    {
        // `audit` declares no undo; even confirmed it offers nothing safe to run at a deadline
        $row = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('audit')->confirm()]);

        $this->assertFalse($this->compensator()->hasConfirmedCompensation($this->def(), $row));
    }

    #[Test]
    public function has_confirmed_compensation_cannot_verify_an_untracked_step(): void
    {
        // `refund` is untracked, with no confirmedBy: at a location-agnostic deadline its effect is unverifiable
        $row = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('refund')]);

        $this->assertFalse($this->compensator()->hasConfirmedCompensation($this->def(), $row));
    }

    #[Test]
    public function compensate_runs_a_confirmed_undo_and_settles_compensated(): void
    {
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')->confirm()]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: true);

        $this->assertSame(WorkflowStatus::Compensated, $result->row->status);
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
    }

    #[Test]
    public function compensate_skips_an_unconfirmed_step_at_a_global_deadline(): void
    {
        // locationAgnostic at a global deadline: an unconfirmed step's effect is unverifiable, so skipped, not undone
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: true);

        $this->assertSame(WorkflowStatus::Halted, $result->row->status); // nothing ran, dead end
        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[0]->status);

        // the audit trail of an all-skipped rollback: flagged + halted, and NOT announced as compensated
        $this->assertCount(1, $this->of($result->announcements, SagaCompensationSkipped::class));
        $this->assertSame(['charge'], $this->of($result->announcements, SagaCompensationSkipped::class)[0]->states);
        $this->assertCount(1, $this->of($result->announcements, SagaHalted::class));
        $this->assertCount(0, $this->of($result->announcements, SagaCompensated::class));
    }

    #[Test]
    public function compensate_undoes_in_reverse_completion_order(): void
    {
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship')->confirm(),
        ]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: false);

        $compensated = $this->of($result->announcements, SagaCompensated::class);
        $this->assertCount(1, $compensated);
        $this->assertSame(['ship', 'charge'], $compensated[0]->states); // reverse of completion order
        $this->assertCount(0, $this->of($result->announcements, SagaHalted::class));
    }

    #[Test]
    public function a_logged_step_without_a_compensation_is_left_untouched_and_does_not_stop_the_rollback(): void
    {
        // defensive: `audit` has no undo to run; its entry stays Pending and the rollback MOVES ON.
        // reverse order processes `audit` first, then `charge` must still be undone
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('audit'),
        ]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
        $this->assertSame(CompensationStatus::Pending, $result->row->compensations[1]->status);
    }

    #[Test]
    public function a_skipped_step_does_not_stop_the_rollback_behind_it(): void
    {
        // reverse order skips `ship`, tracked and unconfirmed, first; `charge` must still be undone
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship'),
        ]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[1]->status);
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
    }

    #[Test]
    public function maybe_compensate_applies_the_positional_eligibility(): void
    {
        // a halt is positional: maybeCompensate must undo an untracked step, though location-agnostic would skip it
        $halted = new Rested($this->row(WorkflowStatus::Halted, [CompensationRecord::pending('refund')]));

        $result = $this->compensator()->maybeCompensate($this->def(), $halted, null);

        $this->assertSame(WorkflowStatus::Compensated, $result->row->status);
    }

    #[Test]
    public function a_degraded_step_is_skipped_with_its_reason_and_its_undo_never_runs(): void
    {
        $undo = new RecordingActivity(ActivityResult::success());
        $def = $this->def(chargeUndo: $undo);
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge', degraded: true)->confirm()]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[0]->status);
        $this->assertSame('degraded — fallback result, undo may not match', $result->row->compensations[0]->reason);
        $this->assertSame(0, $undo->calls);
    }

    #[Test]
    public function an_unconfirmed_tracked_step_is_skipped_at_a_positional_halt_with_its_reason(): void
    {
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[0]->status);
        $this->assertSame('unconfirmed', $result->row->compensations[0]->reason);
    }

    #[Test]
    public function an_untracked_step_at_a_global_deadline_is_skipped_as_unverifiable(): void
    {
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('refund')]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: true);

        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[0]->status);
        $this->assertSame('unverifiable at global deadline', $result->row->compensations[0]->reason);
    }

    #[Test]
    public function a_positional_halt_undoes_an_untracked_step(): void
    {
        // positional: progression past `refund` implies its effect happened; untracked still undoes
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('refund')]);

        $result = $this->compensator()->compensate($this->def(), $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(WorkflowStatus::Compensated, $result->row->status);
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
    }

    #[Test]
    public function compensation_commands_ride_with_the_carried_ones(): void
    {
        // the undo of a command-driven step is itself a durable command: issued ones append to the carried,
        // wrapped with the compensated step as provenance, the rollback's own pairing trace
        $seeded = new IssuedCommand('earlier', new stdClass);
        $issued = new stdClass;
        $def = $this->def(chargeUndo: new RecordingActivity(ActivityResult::success(commands: [$issued])));
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('charge')->confirm()]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted, commands: [$seeded]), null, locationAgnostic: false);

        $this->assertSame($seeded, $result->commands[0]);
        $this->assertSame('charge', $result->commands[1]->fromState);
        $this->assertSame($issued, $result->commands[1]->command);
    }

    #[Test]
    public function strict_mode_stops_at_the_first_failed_undo_leaving_earlier_steps_pending(): void
    {
        $chargeUndo = new RecordingActivity(ActivityResult::success());
        $def = $this->def(CompensationMode::Strict, chargeUndo: $chargeUndo, shipUndo: new RecordingActivity(ActivityResult::failure('boom')));
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship')->confirm(), // undone FIRST in reverse, and it fails
        ]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Failed, $result->row->compensations[1]->status);
        $this->assertSame(CompensationStatus::Pending, $result->row->compensations[0]->status); // left for reconciliation
        $this->assertSame(0, $chargeUndo->calls);
        $this->assertCount(1, $this->of($result->announcements, CompensationFailed::class));
    }

    #[Test]
    public function best_effort_keeps_rolling_back_after_a_failed_undo(): void
    {
        $chargeUndo = new RecordingActivity(ActivityResult::success());
        $def = $this->def(chargeUndo: $chargeUndo, shipUndo: new RecordingActivity(ActivityResult::failure('boom')));
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship')->confirm(),
        ]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Failed, $result->row->compensations[1]->status); // flagged, not fatal
        $this->assertSame('boom', $result->row->compensations[1]->reason); // the undo's own error surfaces
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
        $this->assertSame(1, $chargeUndo->calls);
        $this->assertSame(WorkflowStatus::Compensated, $result->row->status); // an attempted rollback settles Compensated
    }

    #[Test]
    public function a_thrown_undo_settles_failed_and_announces_it_like_a_returned_failure(): void
    {
        // a compensation that THROWS rather than returning ActivityResult::failure lands on the same audit outcome:
        // the catch translates the throwable into a Failed record carrying its audit digest + a CompensationFailed
        $shipUndo = new ThrowingActivity(new RuntimeException('the gateway exploded'));
        $def = $this->def(chargeUndo: new RecordingActivity(ActivityResult::success()), shipUndo: $shipUndo);
        $halted = $this->row(WorkflowStatus::Halted, [CompensationRecord::pending('ship')->confirm()]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Failed, $result->row->compensations[0]->status);
        $this->assertSame('RuntimeException: the gateway exploded', $result->row->compensations[0]->reason); // the throwable surfaces as its audit digest
        $failed = $this->of($result->announcements, CompensationFailed::class);
        $this->assertCount(1, $failed);
        $this->assertSame('ship', $failed[0]->state);
        $this->assertSame('RuntimeException: the gateway exploded', $failed[0]->error);
    }

    #[Test]
    public function strict_mode_stops_the_rollback_at_a_thrown_undo_leaving_the_earlier_step_pending(): void
    {
        // reverse order undoes `ship` FIRST; its undo throws, so Strict halts there and `charge` never runs
        $chargeUndo = new RecordingActivity(ActivityResult::success());
        $def = $this->def(CompensationMode::Strict, chargeUndo: $chargeUndo, shipUndo: new ThrowingActivity(new RuntimeException('boom')));
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship')->confirm(),
        ]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Failed, $result->row->compensations[1]->status); // ship, the thrower
        $this->assertSame(CompensationStatus::Pending, $result->row->compensations[0]->status); // charge, left for reconciliation
        $this->assertSame(0, $chargeUndo->calls); // strict stopped before reaching it
    }

    #[Test]
    public function best_effort_keeps_rolling_back_after_a_thrown_undo(): void
    {
        // same throwing first undo, Lenient BestEffort: the failure is flagged and `charge` is STILL undone
        $chargeUndo = new RecordingActivity(ActivityResult::success());
        $def = $this->def(chargeUndo: $chargeUndo, shipUndo: new ThrowingActivity(new RuntimeException('boom')));
        $halted = $this->row(WorkflowStatus::Halted, [
            CompensationRecord::pending('charge')->confirm(),
            CompensationRecord::pending('ship')->confirm(),
        ]);

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Failed, $result->row->compensations[1]->status); // ship, flagged, not fatal
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status); // charge, ran anyway
        $this->assertSame(1, $chargeUndo->calls);
    }

    #[Test]
    public function undoes_join_arm_compensations_when_rolling_back(): void
    {
        $provisionUndo = new RecordingActivity(ActivityResult::success());
        $chargeUndo = new RecordingActivity(ActivityResult::success());

        $joinPolicy = new JoinPolicy(
            joinedBy: 'await_join',
            arms: [
                'provision' => new JoinArmSlot('provision', stdClass::class, SampleEvent::class, null, $provisionUndo),
                'charge' => new JoinArmSlot('charge', stdClass::class, SampleEvent::class, null, $chargeUndo),
            ]
        );

        $states = [
            'parallel_setup' => new ActivityState('parallel_setup', new RecordingActivity(ActivityResult::success()), join: $joinPolicy),
            'await_join' => new WaitState('await_join'),
            'failing_step' => new ActivityState('failing_step', new RecordingActivity(ActivityResult::failure('boom'))),
        ];

        $def = new WorkflowDefinition('join_flow', $states, 'parallel_setup');

        $halted = new WorkflowInstanceRow(
            'join_flow', 'corr-1', 'failing_step', WorkflowStatus::Halted, [], [], [], 0, null,
            [
                CompensationRecord::forArm('parallel_setup', 'provision', CompensationStatus::Pending, true),
                CompensationRecord::forArm('parallel_setup', 'charge', CompensationStatus::Pending, true),
            ]
        );

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(1, $provisionUndo->calls, 'Join arm "provision" compensation must be called');
        $this->assertSame(1, $chargeUndo->calls, 'Join arm "charge" compensation must be called');
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[1]->status);
    }

    #[Test]
    public function undoes_race_arm_compensation_when_rolling_back(): void
    {
        $fastUndo = new RecordingActivity(ActivityResult::success());
        $racePolicy = new RacePolicy(
            settledBy: 'await_race',
            arms: ['fast' => new RaceArmSlot('fast', stdClass::class, SampleEvent::class, $fastUndo)],
        );

        $states = [
            'race_state' => new ActivityState('race_state', new RecordingActivity(ActivityResult::success()), race: $racePolicy),
            'await_race' => new WaitState('await_race'),
            'failing_step' => new ActivityState('failing_step', new RecordingActivity(ActivityResult::failure('boom'))),
        ];

        $def = new WorkflowDefinition('race_flow', $states, 'race_state');
        $halted = new WorkflowInstanceRow(
            'race_flow', 'corr-1', 'failing_step', WorkflowStatus::Halted, [], [], [], 0, null,
            [CompensationRecord::forArm('race_state', 'fast', CompensationStatus::Pending, true)]
        );

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(1, $fastUndo->calls);
        $this->assertSame(CompensationStatus::Compensated, $result->row->compensations[0]->status);
    }

    #[Test]
    public function skips_an_unknown_arm_on_a_race_or_join_state_defensively(): void
    {
        $joinPolicy = new JoinPolicy(
            joinedBy: 'await_join',
            arms: ['provision' => new JoinArmSlot('provision', stdClass::class, SampleEvent::class, null, new RecordingActivity(ActivityResult::success()))]
        );

        $states = [
            'parallel_setup' => new ActivityState('parallel_setup', new RecordingActivity(ActivityResult::success()), join: $joinPolicy),
            'failing_step' => new ActivityState('failing_step', new RecordingActivity(ActivityResult::failure('boom'))),
        ];

        $def = new WorkflowDefinition('join_flow', $states, 'parallel_setup');
        $halted = new WorkflowInstanceRow(
            'join_flow', 'corr-1', 'failing_step', WorkflowStatus::Halted, [], [], [], 0, null,
            [CompensationRecord::forArm('parallel_setup', 'unknown_ghost_arm', CompensationStatus::Pending, true)]
        );

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: false);

        $this->assertSame(CompensationStatus::Pending, $result->row->compensations[0]->status);
    }

    #[Test]
    public function skips_an_unconfirmed_arm_at_the_global_deadline_location_agnostic(): void
    {
        $joinUndo = new RecordingActivity(ActivityResult::success());
        $raceUndo = new RecordingActivity(ActivityResult::success());

        $joinPolicy = new JoinPolicy(
            joinedBy: 'await_join',
            arms: ['provision' => new JoinArmSlot('provision', stdClass::class, SampleEvent::class, null, $joinUndo)]
        );
        $racePolicy = new RacePolicy(
            settledBy: 'await_race',
            arms: ['fast' => new RaceArmSlot('fast', stdClass::class, SampleEvent::class, $raceUndo)],
        );

        $states = [
            'parallel_setup' => new ActivityState('parallel_setup', new RecordingActivity(ActivityResult::success()), join: $joinPolicy),
            'race_step' => new ActivityState('race_step', new RecordingActivity(ActivityResult::success()), race: $racePolicy),
            'failing_step' => new ActivityState('failing_step', new RecordingActivity(ActivityResult::failure('boom'))),
        ];

        $def = new WorkflowDefinition('flow', $states, 'parallel_setup');
        $halted = new WorkflowInstanceRow(
            'flow', 'corr-1', 'failing_step', WorkflowStatus::Halted, [], [], [], 0, null,
            [
                CompensationRecord::forArm('parallel_setup', 'provision', CompensationStatus::Pending, false),
                CompensationRecord::forArm('race_step', 'fast', CompensationStatus::Pending, false),
            ]
        );

        $result = $this->compensator()->compensate($def, $halted, new Rested($halted), null, locationAgnostic: true);

        $this->assertSame(0, $joinUndo->calls);
        $this->assertSame(0, $raceUndo->calls);
        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[0]->status);
        $this->assertSame('unconfirmed', $result->row->compensations[0]->reason);
        $this->assertSame(CompensationStatus::Skipped, $result->row->compensations[1]->status);
        $this->assertSame('unconfirmed', $result->row->compensations[1]->reason);
    }

    private function compensator(): Compensator
    {
        return new Compensator(new MutableClock);
    }

    /**
     * - `charge`: undo, confirmedBy stdClass
     * - `ship`: undo, confirmedBy SampleEvent
     * - `audit`: no compensation
     * - `refund`: undo, untracked.
     */
    private function def(CompensationMode $mode = CompensationMode::BestEffort, ?Activity $chargeUndo = null, ?Activity $shipUndo = null): WorkflowDefinition
    {
        $forward = static fn (): RecordingActivity => new RecordingActivity(ActivityResult::success());

        $states = [
            'charge' => new ActivityState('charge', $forward(), compensation: $chargeUndo ?? $forward(), compensationConfirmedBy: stdClass::class),
            'ship' => new ActivityState('ship', $forward(), compensation: $shipUndo ?? $forward(), compensationConfirmedBy: SampleEvent::class),
            'settle' => new ActivityState('settle', $forward(), compensation: $forward(), compensationConfirmedBy: SettlementContract::class),
            'audit' => new ActivityState('audit', $forward()),
            'refund' => new ActivityState('refund', $forward(), compensation: $forward()),
            'await_x' => new WaitState('await_x'),
        ];

        return new WorkflowDefinition('payment', $states, 'charge', compensation: $mode);
    }

    /**
     * @param  list<CompensationRecord>  $compensations
     */
    private function row(WorkflowStatus $status, array $compensations): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'o-1', 'charge', $status, [], [], [], 0, null, $compensations);
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
