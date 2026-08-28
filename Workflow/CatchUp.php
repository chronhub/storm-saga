<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * What a schedule state does with the slots it missed while the worker was down: the catch-up policy,
 * named after Temporal Schedules' CatchupWindow and OverlapPolicy split. When the cadence timer fires
 * late, more than one grid slot may have come due:
 *
 * - `Skip`: fire one tick, the latest, drop the gap, re-anchor to the next future slot. The default.
 * - `ReplayBounded`: fire up to `catchUpLimit` of the missed slots.
 * - `ReplayAll`: fire every missed slot still within `catchUpWindowSeconds`.
 *
 * `ReplayBounded` and `ReplayAll` fire the period's work more than once, so they require an idempotent
 * tick; the build asserts the dev opted in with `idempotentTick: true` and cannot prove idempotence, the
 * twin of the `retriable` footgun.
 *
 * @see CatchUpPolicy
 */
enum CatchUp: string
{
    case Skip = 'skip';

    case ReplayBounded = 'bounded';

    case ReplayAll = 'all';
}
