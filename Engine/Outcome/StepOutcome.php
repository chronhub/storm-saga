<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

/**
 * What a performed plan asks the step's shell to do, a closed sum sealed by package convention;
 * consume it with an instanceof `match` and no default arm:
 *
 * - {@see Created}: a fresh instance to insert, plus its effects and announcements.
 * - {@see Updated}: the advanced row to persist under OCC, plus its effects and announcements.
 * - {@see Effects}: no row change at all, an escalation, so timers and announcements only.
 * - {@see Nothing}: the step did nothing; the engine reports a plain false.
 */
interface StepOutcome {}
