<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

use Storm\Saga\Event\SagaHalted;

/**
 * The global cap fell on a non-retriable effect-gating wait: an effect is in flight that no deadline
 * may finalize and no compensation may safely undo, since releasing a possibly committed effect
 * would create money. So the cap is honored as a bound, not a business path: halt on the spot, flag
 * the logged steps for reconciliation, drive nothing. The saga becomes terminal and observable
 * instead of re-arming the global forever; a late outcome event, a dead-letter, or the recon
 * backstop resolves the in-flight leg.
 *
 * Planned only by the fired `Global` timer at a non-retriable gating wait; the non-gating case is
 * handled elsewhere, which may drive `onGlobalTimeout`, halt, or roll back a confirmed step. A
 * retriable gating wait waives the cap, disarming its timer, to ride its heartbeat instead.
 *
 * @see SagaHalted
 * @see SettleFailedEffect
 * @see EnforceGlobalDeadline
 * @see WaiveGlobalCap
 */
final readonly class HaltAtGlobalCap implements StepPlan {}
