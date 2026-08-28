<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * A per-wait deadline elapsed while an effect-gating wait is still in flight: re-arm the wait's own
 * timeout, a recurring liveness heartbeat, and announce SagaAwaitEscalated, never finalize. The
 * instance row is untouched; the wait resolves only when its outcome event is delivered.
 *
 * Only the per-state timer escalates: the global cap no longer re-arms here; at a gating wait it
 * bounds the saga instead, so this plan carries no timer kind.
 *
 * @see HaltAtGlobalCap
 */
final readonly class EscalateWait implements StepPlan {}
