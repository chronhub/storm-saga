<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\State;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\Transition as Edge;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class ScheduleRunnerTest extends TestCase
{
    /** An hourly grid anchored at midnight. The catch-up tests phase on it so their slots land on the hour. */
    private const string ANCHOR = '2026-06-15T00:00:00.000000Z';

    #[Test]
    public function a_due_slot_fires_the_schedule_edge_with_one_tick(): void
    {
        $now = PointInTime::from('2026-06-15T11:00:00.000000Z');
        // due exactly now, so no missed slots
        $verdict = $this->runner()->run($this->ctx($this->state(CatchUp::Skip), Stimulus::schedule($now), $now, anchor: PointInTime::from(self::ANCHOR)));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame('charge', $verdict->to);
        $this->assertSame(OnTrigger::Schedule, $verdict->trigger);
        $this->assertSame(1, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function replay_all_fires_a_tick_for_every_missed_slot(): void
    {
        // fired at 10:00, processed at 13:00 over an hourly cadence, so 10:00 + 11/12/13 missed = 4
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::ReplayAll),
            Stimulus::schedule(PointInTime::from('2026-06-15T10:00:00.000000Z')),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(4, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function skip_collapses_a_late_fire_to_one_tick(): void
    {
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::Skip),
            Stimulus::schedule(PointInTime::from('2026-06-15T10:00:00.000000Z')),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(1, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function bounded_caps_a_late_fire_at_the_limit(): void
    {
        // 4 slots due, capped at 2
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::ReplayBounded, limit: 2),
            Stimulus::schedule(PointInTime::from('2026-06-15T10:00:00.000000Z')),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(2, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function the_replay_all_window_floors_how_far_back_catch_up_reaches(): void
    {
        // fired 5h ago, but the 2h window floors catch-up at 11:00, so the fired slot + 12:00/13:00 = 3
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::ReplayAll, windowSeconds: 7200),
            Stimulus::schedule(PointInTime::from('2026-06-15T08:00:00.000000Z')),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(3, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function entering_the_state_arms_the_next_slot_anchored_to_the_instance(): void
    {
        // born at :17 past, so the re-armed slot is :17, not :00. The startedAt anchor drives the grid.
        $now = PointInTime::from('2026-06-15T10:30:00.000000Z');
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::Skip),
            Stimulus::none(),
            $now,
            anchor: PointInTime::from('2026-06-15T10:17:00.000000Z'),
        ));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame(TimerOpKind::ArmSchedule, $verdict->timerOps[0]->kind);
        $this->assertSame('await', $verdict->timerOps[0]->stateKey);
        $this->assertSame('2026-06-15T11:17:00.000000+00:00', $verdict->timerOps[0]->at?->toString());
    }

    #[Test]
    public function a_due_slot_with_no_schedule_edge_halts(): void
    {
        $now = PointInTime::from('2026-06-15T11:00:00.000000Z');
        $state = new ScheduleState('await', new IntervalCadence(3600), new CatchUpPolicy(CatchUp::Skip)); // no edge

        $this->assertInstanceOf(Halt::class, $this->runner()->run($this->ctx($state, Stimulus::schedule($now), $now, $now)));
    }

    #[Test]
    public function a_fire_carries_the_saga_vars_through_alongside_the_tick_count(): void
    {
        $now = PointInTime::from('2026-06-15T11:00:00.000000Z');
        $verdict = $this->runner()->run($this->ctx($this->state(CatchUp::Skip), Stimulus::schedule($now), $now, $now, ['remaining' => 7]));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(7, $verdict->vars['remaining']); // the spread carried the saga's own vars through the tick
        $this->assertSame(1, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    public function a_per_instance_cron_arms_the_slot_resolved_from_the_instance_vars(): void
    {
        $now = PointInTime::from('2026-06-01T00:00:00.000000Z');
        $anchor = PointInTime::from(self::ANCHOR);

        // instance A bills on the 15th; the slot is resolved from ITS vars
        $a = $this->runner()->run($this->ctx($this->cronState(), Stimulus::none(), $now, $anchor, ['billing_cron' => '0 0 15 * *']));
        $this->assertInstanceOf(Stay::class, $a);
        $this->assertStringContainsString('2026-06-15', (string) $a->timerOps[0]->at?->toString());

        // instance B, the SAME schedule state, bills on the 20th, so it arms a DIFFERENT slot from its own vars
        $b = $this->runner()->run($this->ctx($this->cronState(), Stimulus::none(), $now, $anchor, ['billing_cron' => '0 0 20 * *']));
        $this->assertInstanceOf(Stay::class, $b);
        $this->assertStringContainsString('2026-06-20', (string) $b->timerOps[0]->at?->toString());
    }

    #[Test]
    public function a_per_instance_cron_resolved_to_garbage_fails_loud_at_the_tick(): void
    {
        $now = PointInTime::from('2026-06-01T00:00:00.000000Z');

        $this->expectException(InvalidResolvedCron::class);
        $this->runner()->run($this->ctx($this->cronState(), Stimulus::none(), $now, PointInTime::from(self::ANCHOR), ['billing_cron' => 'not a cron']));
    }

    #[Test]
    public function a_spread_schedule_arms_the_instance_translated_slot(): void
    {
        // corr-1 gives crc32 % 3600 = 3127, so the hourly midnight grid is
        // translated to :52:07 for THIS instance; the unshifted slot would be 11:00
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::Skip, spreadSeconds: 3600),
            Stimulus::none(),
            PointInTime::from('2026-06-15T10:30:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame('2026-06-15T10:52:07.000000+00:00', $verdict->timerOps[0]->at?->toString());
    }

    #[Test]
    public function two_instances_of_the_same_schedule_arm_distinct_slots(): void
    {
        // the de-synchronization property itself: a batch-born pair, same state, same now, same
        // anchor, still lands on different slots; corr-1 gives +3127s, corr-2 gives +1229s
        $state = $this->state(CatchUp::Skip, spreadSeconds: 3600);
        $now = PointInTime::from('2026-06-15T10:30:00.000000Z');
        $anchor = PointInTime::from(self::ANCHOR);

        $a = $this->runner()->run($this->ctx($state, Stimulus::none(), $now, $anchor, correlationId: 'corr-1'));
        $b = $this->runner()->run($this->ctx($state, Stimulus::none(), $now, $anchor, correlationId: 'corr-2'));

        $this->assertInstanceOf(Stay::class, $a);
        $this->assertInstanceOf(Stay::class, $b);
        $this->assertSame('2026-06-15T10:52:07.000000+00:00', $a->timerOps[0]->at?->toString());
        $this->assertSame('2026-06-15T11:20:29.000000+00:00', $b->timerOps[0]->at?->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_pre_spread_timer_fires_without_a_phantom_catch_up(): void
    {
        // The live-fleet transition: this timer was armed BEFORE spread was declared, dueAt 10:00
        // sits on the UNSHIFTED grid, and the runner now counts over the shifted one. The window
        // translation moves WHICH slots count, never materializes extra ones: 3h late on an hourly
        // grid stays 4 ticks, the fired slot + 3, exactly what the unshifted grid would have said.
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::ReplayAll, spreadSeconds: 3600),
            Stimulus::schedule(PointInTime::from('2026-06-15T10:00:00.000000Z')),
            PointInTime::from('2026-06-15T13:00:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Transition::class, $verdict);
        $this->assertSame(4, $verdict->vars[ScheduleRunner::TICKS]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_spread_of_one_second_leaves_every_grid_unshifted(): void
    {
        // spread = 1 forces offset 0 for EVERY identity, x % 1 = 0; pins the zero-offset path:
        // the decoration is skipped and the armed slot is exactly the declared grid's
        $verdict = $this->runner()->run($this->ctx(
            $this->state(CatchUp::Skip, spreadSeconds: 1),
            Stimulus::none(),
            PointInTime::from('2026-06-15T10:30:00.000000Z'),
            anchor: PointInTime::from(self::ANCHOR),
        ));

        $this->assertInstanceOf(Stay::class, $verdict);
        $this->assertSame('2026-06-15T11:00:00.000000+00:00', $verdict->timerOps[0]->at?->toString());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_wrong_state_subtype_halts_defensively(): void
    {
        // The MachineRunner dispatches by subtype before calling a runner directly; calling ScheduleRunner
        // with a WaitState proves the guard returns Halt instead of dereferencing the wrong subtype.
        $state = new WaitState('wrong', eventClasses: []);
        $ctx = new StateContext(
            'wf',
            'corr-1',
            new WorkflowDefinition('wf', [$state->key => $state], $state->key),
            $state,
            Stimulus::none(),
            PointInTime::from('2026-01-01T00:00:00.000000Z'),
            anchor: PointInTime::from('2026-01-01T00:00:00.000000Z'),
        );

        $this->assertInstanceOf(Halt::class, $this->runner()->run($ctx));
    }

    private function runner(): ScheduleRunner
    {
        return new ScheduleRunner(new TransitionSelector);
    }

    /** A schedule state whose cron is resolved per-instance from `vars['billing_cron']`, with no fixed cadence. */
    private function cronState(): ScheduleState
    {
        return new ScheduleState(
            'await',
            null,
            new CatchUpPolicy(CatchUp::Skip),
            [new Edge(OnTrigger::Schedule, 'charge')],
            static fn (array $vars): string => (string) ($vars['billing_cron'] ?? ''),
        );
    }

    private function state(CatchUp $mode, ?int $limit = null, ?int $windowSeconds = null, ?int $spreadSeconds = null): ScheduleState
    {
        return new ScheduleState(
            'await',
            new IntervalCadence(3600),
            new CatchUpPolicy($mode, $limit, $windowSeconds),
            [new Edge(OnTrigger::Schedule, 'charge')],
            null,
            $spreadSeconds,
        );
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function ctx(ScheduleState $state, Stimulus $stimulus, PointInTime $now, PointInTime $anchor, array $vars = [], string $correlationId = 'corr-1'): StateContext
    {
        return new StateContext('wf', $correlationId, new WorkflowDefinition('wf', [$state->key => $state], $state->key), $state, $stimulus, $now, $anchor, $vars);
    }
}
