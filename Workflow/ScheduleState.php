<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Closure;
use LogicException;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Exception\InvalidResolvedInterval;
use Storm\Saga\Workflow\Cadence\Cadence;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\Cadence\SpreadCadence;
use Throwable;

/**
 * Re-fires on a recurring `Cadence`, interval, or cron: each time a slot comes due the state yields the
 * `Schedule` trigger, whose target is a separate `#[On(trigger: 'schedule')]` edge, typically an activity
 * that does the period's work, then, re-entered, arms the next slot. A durable state that ticks, not a
 * saga looping in memory: nothing runs between slots, and the deadline lives in the timer row. The
 * `$catchUp` policy decides how many missed slots a late fire replays.
 *
 * The cadence is either fixed or per-instance. Fixed cadence uses `$cadence`, a literal interval or cron
 * built once; per-instance uses `$cronResolver`, a calendar cron resolved from the instance's vars at each
 * tick, such as the customer's own billing day, or `$intervalResolver`, an anniversary-phased interval
 * resolved the same way, such as a risk tier's review period. Exactly one of the three is non-null,
 * build-enforced, and `cadenceFor()` picks per tick.
 *
 * `$spreadSeconds` declares the grid spread, the deterministic per-instance translation that
 * de-synchronizes a batch-born fleet, carried here as declared; the runner derives the instance's offset
 * and decorates the cadence, because only it holds the instance's identity.
 *
 * @see SpreadCadence
 */
final class ScheduleState extends State
{
    /**
     * @param  (Closure(array<string, mixed>): string)|null  $cronResolver  resolves the per-instance cron expression
     *                                                                      from vars via cronVar or cronMethod; null for a fixed cadence
     * @param  int|null  $spreadSeconds  the declared grid spread, positive since the build rejects a non-positive one before constructing this; null keeps the grid as declared
     * @param  (Closure(array<string, mixed>): int)|null  $intervalResolver  resolves the per-instance interval in seconds
     *                                                                       from vars via intervalVar or intervalMethod; null for a fixed cadence
     * @param  string|null  $cadenceVar  the declared per-instance SOURCE, kept as metadata beside the built resolver:
     *                                   the vars key `cronVar` or `intervalVar` the closure reads; description
     *                                   surfaces render it, the tick never reads it
     * @param  string|null  $cadenceMethod  same metadata for the bound-method source `cronMethod` or `intervalMethod`;
     *                                      at most one of var/method is non-null, mirroring the attribute's
     *                                      exactly-one guard
     */
    public function __construct(
        string $key,
        public readonly ?Cadence $cadence,
        public readonly CatchUpPolicy $catchUp,
        array $transitions = [],
        public readonly ?Closure $cronResolver = null,
        public readonly ?int $spreadSeconds = null,
        public readonly ?Closure $intervalResolver = null,
        public readonly ?string $cadenceVar = null,
        public readonly ?string $cadenceMethod = null,
    ) {
        parent::__construct($key, $transitions);
    }

    /**
     * The cadence to drive this tick by: the fixed cadence, an interval or literal cron, or a per-instance
     * one freshly resolved from the instance's vars, a `CronCadence` via cronVar or cronMethod, an
     * `IntervalCadence` via intervalVar or intervalMethod, built and validated now. Resolved at each tick,
     * so a changed billing day or review period takes effect on the next slot.
     *
     * @param  array<string, mixed>  $vars
     *
     * @throws InvalidResolvedCron when a per-instance cron resolves to an expression that does not parse
     * @throws InvalidResolvedInterval when a per-instance interval resolves to a non-positive number of seconds
     */
    public function cadenceFor(array $vars): Cadence
    {
        if ($this->cadence !== null) {
            return $this->cadence;
        }

        if ($this->intervalResolver !== null) {
            $seconds = ($this->intervalResolver)($vars);

            return $seconds >= 1
                ? new IntervalCadence($seconds)
                : throw InvalidResolvedInterval::forState($this->key, $seconds);
        }

        $resolver = $this->cronResolver
            ?? throw new LogicException(sprintf('Schedule state "%s" has neither a fixed cadence nor a per-instance resolver.', $this->key));
        $expression = $resolver($vars);

        try {
            return new CronCadence($expression);
        } catch (Throwable $e) {
            throw InvalidResolvedCron::forState($this->key, $expression, $e);
        }
    }
}
