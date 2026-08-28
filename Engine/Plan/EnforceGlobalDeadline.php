<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * The global deadline elapsed with nothing in flight to protect: force the saga's resolution by
 * rolling back the confirmed steps, halting, or driving from `onGlobalTimeout`, the performer's
 * three branches. Planned either by the fired `Global` timer, trusted as-is, or by the deadline
 * guard a late event or timer trips, whose trigger is then discarded; the deadline is authoritative.
 */
final readonly class EnforceGlobalDeadline implements StepPlan {}
