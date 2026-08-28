<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Workflow\Cadence\Cadence;
use Storm\Saga\Workflow\Cadence\SpreadCadence;
use Storm\Saga\Workflow\Cadence\SpreadOffset;
use Storm\Saga\Workflow\ScheduleState;

/**
 * Runs a schedule state. On a due slot, the `schedule` stimulus carrying the fired instant, count
 * the slots missed since it, apply the catch-up policy, write the resulting tick count into `vars`
 * under `schedule_ticks`, and take the `Schedule` transition, typically to an activity that does the
 * period's work. With no stimulus, on entry or chaining back in, arm the next slot and stay.
 *
 * The re-arm replaces the timer row, an idempotent upsert, and crossing the edge cancels the fired
 * one through the cursor, so the cadence never double-fires. `schedule_ticks`, at least 1, tells the
 * tick how many periods to settle; a value greater than 1 is a catch-up, which is why ReplayBounded
 * and ReplayAll demand an idempotent tick.
 */
final readonly class ScheduleRunner
{
    /** The `vars` key carrying how many periods this tick must settle, at least 1; more than 1 is a catch-up. */
    public const string TICKS = 'schedule_ticks';

    public function __construct(
        private TransitionSelector $selector,
    ) {}

    /**
     * @throws ClockExceptionContract when the next slot instant cannot be derived
     * @throws InvalidResolvedCron when a per-instance cron resolves to an expression that does not parse
     */
    public function run(StateContext $ctx): Transition|Stay|Halt
    {
        $state = $ctx->state;
        if (! $state instanceof ScheduleState) {
            return new Halt; // defensive: the machine dispatches by subtype
        }

        // the per-instance grid is phased on the saga's birth, startedAt, resolved upstream; MachineRunner
        // already coalesced a type-nullable missing startedAt to now, so this is always present
        $anchor = $ctx->anchor;

        $dueAt = $ctx->stimulus->scheduleDueAtOrNull();
        if ($dueAt !== null) {
            return $this->fire($state, $dueAt, $anchor, $ctx);
        }

        // entered or chained back into the state, so arm the next slot, rest. cadenceFor resolves a
        // per-instance cron from vars at each tick; a fixed cadence is returned as-is.
        return new Stay([TimerOp::armScheduleAt($state->key, $this->cadenceOf($state, $ctx)->nextFireAfter($ctx->now, $anchor))], $ctx->vars);
    }

    /**
     * The instance's cadence for this tick: the state's declared grid, spread-translated when the
     * schedule declares one. The offset is derived here, since the runner is the one place holding
     * both the declaration and the instance's identity, and re-derived identically at every tick, a
     * pure function of the correlation id, so the armed timer and the later countDue always see the
     * same grid. A zero offset, where the instance happens to sit on the unshifted grid, skips the
     * decoration.
     *
     * @throws InvalidResolvedCron when a per-instance cron resolves to an expression that does not parse
     */
    private function cadenceOf(ScheduleState $state, StateContext $ctx): Cadence
    {
        $cadence = $state->cadenceFor($ctx->vars);
        $spread = $state->spreadSeconds;
        if ($spread === null) {
            return $cadence;
        }

        /** @var int<1, max> $spread the build rejects a non-positive spread before a ScheduleState exists */
        $offset = SpreadOffset::for($ctx->correlationId, $spread);

        // @infection-ignore-all; equivalent: an offset-0 decoration is the identity translation; the
        // skip only saves the allocation, either branch arms the same grid
        return $offset === 0 ? $cadence : new SpreadCadence($cadence, $offset);
    }

    /**
     * A slot came due: how many periods does this tick settle? The fired slot is one; the catch-up
     * policy decides how many of the slots missed since it, bounded by its window, ride along.
     *
     * @throws ClockExceptionContract when the window floor or a missed-slot instant cannot be derived
     * @throws InvalidResolvedCron when a per-instance cron resolves to an expression that does not parse
     */
    private function fire(ScheduleState $state, PointInTime $dueAt, PointInTime $anchor, StateContext $ctx): Transition|Halt
    {
        $floor = $state->catchUp->windowFloor($ctx->now);
        $after = $floor !== null && $floor->isAfter($dueAt) ? $floor : $dueAt;

        $due = 1 + $this->cadenceOf($state, $ctx)->countDue($after, $ctx->now, $anchor); // the fired slot + the slots missed since it
        $vars = [...$ctx->vars, self::TICKS => $state->catchUp->ticksFor($due)];

        $to = $this->selector->select($state, OnTrigger::Schedule, $vars);

        return $to === null
            ? new Halt
            : new Transition($to, OnTrigger::Schedule, $vars);
    }
}
