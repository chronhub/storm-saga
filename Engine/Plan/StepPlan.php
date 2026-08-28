<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

use Storm\Saga\Engine\StepPolicy;

/**
 * What a Signal is allowed to do to an instance, now, the StepPolicy's verdict as a value. A closed
 * sum sealed by package convention; consume it with an instanceof `match` and no default arm, so an
 * unknown case fails fast via the native UnhandledMatchError:
 *
 * - {@see StartInstance}: create a fresh instance at the start state and drive it.
 * - {@see AdvanceInstance}: run the machine from the loaded row with a Stimulus.
 * - {@see EscalateWait}: re-arm an effect-gating wait's own timeout heartbeat, never finalize.
 * - {@see EnforceGlobalDeadline}: force the elapsed global deadline at a non-gating resting point.
 * - {@see HaltAtGlobalCap}: the cap fell on a gating wait: halt and flag for reconciliation, never finalize.
 * - {@see WaiveGlobalCap}: the cap fell on a retriable gating wait: stamp `waived_at`, disarm both timers, hand off.
 * - {@see SettleFailedEffect}: settle a saga whose issued command was dead-lettered and paired to it.
 * - {@see CancelInstance}: cancel the saga, by operator or dead-letter, compensating what is safe.
 * - {@see ApplyUserSignal}: invoke the declared handler for a user signal: vars move, no transition.
 * - {@see Skip}: do nothing, with the reason.
 *
 * @see StepPolicy
 */
interface StepPlan {}
