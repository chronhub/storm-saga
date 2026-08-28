<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * A saga-issued command was dead-lettered while the saga is parked at its effect-gating wait. A
 * dead-letter means the handler transaction rolled back, so no effect committed and settling is
 * safe: halt at the wait and compensate for the earlier confirmed steps. Ignores the global deadline
 * entirely, since the safe settle has no expiry window.
 */
final readonly class SettleFailedEffect implements StepPlan {}
