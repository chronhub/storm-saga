<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Run;

/**
 * Nothing happened, an event that matched nothing on the first hop. Carries nothing, since the
 * callers already discard everything on a no-op, so transporting data here would be dead weight.
 */
final readonly class Unmoved implements MachineRun {}
