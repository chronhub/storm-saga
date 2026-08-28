<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\CatchUp;
use ValueError;

/**
 * Configures a schedule state, the recurrence twin of `#[WaitFor]` for a wait: its cadence, exactly one of
 * six sources, and its catch-up behavior when the worker was down across missed slots. `$catchUp` selects
 * that behavior, with `$catchUpLimit` for ReplayBounded and `$catchUpWindowSeconds` for ReplayAll.
 *
 * The cadence sources, two families of three, literal, vars key, workflow method:
 *
 * - `$intervalSeconds`: a fixed interval, per-instance, phased on the saga's birth anniversary.
 *
 * - `$intervalVar`: a vars key whose value is the interval in seconds, resolved from `vars` at each tick;
 *   anniversary-phased like the literal, but each instance carries its own period, such as a risk tier's
 *   review cycle.
 *
 * - `$intervalMethod`: a workflow method `fn(array $vars): int` returning the interval in seconds, the
 *   matcher-style twin of `$intervalVar` when the period must be computed.
 *
 * - `$cron`: a literal calendar expression, global, the same slots for every instance such as the 1st of
 *   the month.
 *
 * - `$cronVar`: a vars key whose value is the cron expression, a per-instance calendar such as the
 *   customer's own billing day, resolved from `vars` at each tick; calendar and personalized.
 *
 * - `$cronMethod`: a workflow method `fn(array $vars): string` returning the cron, the matcher-style twin
 *   of `$cronVar` when the expression must be computed.
 *
 * Interval versus cron is a semantic choice, not a syntax one: an interval grid is anchored on the
 * instance's own birth, the anniversary semantics of a review due N periods after opening, while a cron
 * grid is anchored on the calendar, the semantics of a due DATE. A per-instance source resolves at each
 * tick, so a changed vars value takes effect on the next slot; the build can only check that the method
 * exists, the resolved value being a runtime guard, `InvalidResolvedCron` or `InvalidResolvedInterval`.
 *
 * `$idempotentTick` is the dev's assertion that the period's work is safe to replay; the build requires it
 * when `$catchUp` is not `Skip` and the engine cannot prove idempotence, the twin of the `retriable`
 * footgun.
 *
 * `$spreadSeconds` de-synchronizes a fleet born in a burst, such as a mass migration or import whose
 * batched births otherwise mean coinciding slots forever: each instance's whole grid is translated by a
 * deterministic offset, `crc32(correlationId) % spreadSeconds`, never random so replay stays stable,
 * holding no state and recomputed identically at every tick. Full-period spread on an interval grid sets
 * `spreadSeconds` equal to the interval. On a calendar grid it shifts the calendar instant itself, the 1st
 * plus the instance's offset, a business decision to make consciously: an installment due on a date must
 * not spread. Enabling it on a live fleet translates each grid once, giving one irregular inter-fire gap
 * whose catch-up count can see plus or minus one slot at that boundary; `idempotentTick` and `Skip` are
 * the standing guards for exactly those effects.
 *
 * Grammar: this references a state, so it says `state:` like `WaitFor` and `Retry`; the
 * `#[State(type: StateType::Schedule)]` declares the key.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Schedule
{
    public CatchUp $catchUp;

    /**
     * @param  int|null  $intervalSeconds  a fixed-interval cadence, one of the six sources
     * @param  string|null  $intervalVar  a vars key holding the interval in seconds, resolved per-instance at each tick
     * @param  string|null  $intervalMethod  a workflow method `fn(array $vars): int` returning the interval in seconds
     * @param  string|null  $cron  a literal, global calendar cadence as a standard cron expression
     * @param  string|null  $cronVar  a vars key holding the cron expression, resolved per-instance at each tick
     * @param  string|null  $cronMethod  a workflow method `fn(array $vars): string` returning the cron expression
     * @param  string|CatchUp  $catchUp  what a late fire does with missed slots: 'skip' | 'bounded' | 'all'
     * @param  int|null  $catchUpLimit  ReplayBounded: the max missed slots to replay
     * @param  int|null  $catchUpWindowSeconds  ReplayAll: how far back missed slots stay eligible
     * @param  int|null  $spreadSeconds  deterministic per-instance grid translation within [0, spreadSeconds); de-synchronizes a batch-born fleet; null keeps the grid as declared
     *
     * @throws ValueError when `$catchUp` is a string that is not a valid CatchUp
     */
    public function __construct(
        public string $state,
        public ?int $intervalSeconds = null,
        public ?string $intervalVar = null,
        public ?string $intervalMethod = null,
        public ?string $cron = null,
        public ?string $cronVar = null,
        public ?string $cronMethod = null,
        string|CatchUp $catchUp = CatchUp::Skip,
        public ?int $catchUpLimit = null,
        public ?int $catchUpWindowSeconds = null,
        public bool $idempotentTick = false,
        public ?int $spreadSeconds = null,
    ) {
        $this->catchUp = is_string($catchUp) ? CatchUp::from($catchUp) : $catchUp;
    }
}
