<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Store\WorkflowTimerStore;

/**
 * A timer instruction, collected as data by the pure machine and applied in order by the step's
 * shell, the only place the clock turns instructions into rows. Most arms are relative, a delay in
 * seconds or ms the shell adds to `now`; the schedule arm is the one absolute kind, a calendar slot
 * applied as-is. The order is a contract: a drive can leave state X and later rest on X again,
 * `cancel X … arm X`, so applying out of order would strand a wait without its timer.
 *
 * Every op carries its state key; the global-deadline ops carry the store's reserved sentinel
 * `WorkflowTimerStore::GLOBAL_KEY`, so the shell applies every kind uniformly. Built only through
 * the per-kind factories: construction guarantees a kind's fields, `seconds` on the timeout and
 * global arms, `delayMs` on the kick, and `at` on the schedule.
 */
final readonly class TimerOp
{
    private function __construct(
        public TimerOpKind $kind,
        public string $stateKey,
        public ?int $seconds = null,
        public ?int $delayMs = null,
        public ?PointInTime $at = null,
        public ?int $businessDays = null,
        public ?int $businessHours = null,
    ) {}

    /**
     * Arm `$stateKey`'s timeout, `$seconds` from now, floored to 1s by the shell.
     */
    public static function armTimeout(string $stateKey, int $seconds): self
    {
        return new self(TimerOpKind::ArmTimeout, $stateKey, seconds: $seconds);
    }

    /**
     * Arm `$stateKey`'s timeout in business time: the shell resolves it through the BusinessCalendar,
     * skipping weekends, holidays, and out-of-hours, not `now + seconds`. The same ArmTimeout kind;
     * the shell's `armAt` picks the conversion by whether the business fields are set.
     */
    public static function armBusinessTimeout(string $stateKey, ?int $businessDays, ?int $businessHours): self
    {
        return new self(TimerOpKind::ArmTimeout, $stateKey, businessDays: $businessDays, businessHours: $businessHours);
    }

    /**
     * Arm a back-off kick on `$stateKey`, `$delayMs` from now; null means now, and sub-second rounds
     * up to 1s.
     */
    public static function armKick(string $stateKey, ?int $delayMs): self
    {
        return new self(TimerOpKind::ArmKick, $stateKey, delayMs: $delayMs);
    }

    /**
     * Arm the instance-wide global deadline, `$seconds` from now, floored to 1s by the shell.
     */
    public static function armGlobal(int $seconds): self
    {
        return new self(TimerOpKind::ArmGlobal, WorkflowTimerStore::GLOBAL_KEY, seconds: $seconds);
    }

    /**
     * Arm `$stateKey`'s schedule timer at the absolute instant `$at`, a calendar slot, not a
     * relative delay.
     */
    public static function armScheduleAt(string $stateKey, PointInTime $at): self
    {
        return new self(TimerOpKind::ArmSchedule, $stateKey, at: $at);
    }

    /**
     * Drop every timer of `$stateKey`, whether leaving the state or settling on it.
     */
    public static function cancelState(string $stateKey): self
    {
        return new self(TimerOpKind::CancelState, $stateKey);
    }

    /**
     * Drop the instance-wide global deadline, consumed or moot after a settle.
     */
    public static function cancelGlobal(): self
    {
        return new self(TimerOpKind::CancelGlobal, WorkflowTimerStore::GLOBAL_KEY);
    }
}
