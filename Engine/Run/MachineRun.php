<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Run;

/**
 * What one machine run produced, the unit of a saga advance, whatever its origin: the forward drive,
 * a compensation, or the deadline enforcement. A closed sum sealed by package convention; consume it
 * with an instanceof `match` and no default arm:
 *
 * - {@see Rested}: the saga moved and rests somewhere, carrying the row to persist, the ordered
 *   timer ops, the issued commands, and the `Saga*` announcements to dispatch after commit.
 *
 * - {@see Unmoved}: nothing happened, an event that matched nothing, so it carries nothing, because
 *   nothing is persisted, applied, or announced.
 */
interface MachineRun {}
