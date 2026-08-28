<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

use Storm\Saga\Engine\DeadlineEnforcer;

/**
 * The global cap fell on a retriable effect-gating wait: the leg's success is inevitable post-pivot,
 * so the cap must not halt it, invariant 2, since a deadline never finalizes an in-flight effect.
 * But the saga must not keep re-arming for nothing either; a lost, not merely lagging, confirmation
 * would leave the spent global timer re-claiming every lease and the wait's heartbeat re-pinging
 * every interval, forever. So the cap is waived: {@see DeadlineEnforcer::waiveAtCap()} disarms both
 * timers and emits a one-shot `SagaAwaitOverdue`, the hand-off to reconciliation; the saga goes
 * quiet with zero timers, still non-terminal. A late outcome still resolves the wait via onEvent,
 * and the instance-wide deadline stays enforceable for any later non-gating signal through the clock
 * check pastGlobalDeadline, which trusts `startedAt + globalTimeout`, not the disarmed timer rows.
 * The cap thus bounds the saga's churn, the only bound invariant 2 allows, not its existence;
 * reconciliation or the at-rest TTL resolves what outlives it.
 *
 * Planned only by the fired `Global` timer at a retriable gating wait, the sibling of
 * {@see HaltAtGlobalCap}, the non-retriable case, which halts and flags for reconciliation.
 */
final readonly class WaiveGlobalCap implements StepPlan {}
