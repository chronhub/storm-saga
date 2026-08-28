<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

/**
 * What one state's runner concluded, a closed sum sealed by package convention; consume it with an
 * instanceof `match` and no default arm:
 *
 * - {@see Transition}: take an edge to a target state; the target is non-nullable by construction.
 * - {@see Stay}: rest in the same state, optionally re-arming its timers.
 * - {@see Finalize}: a final state was reached, the saga completes.
 * - {@see Halt}: a dead end, no matching transition where one was needed.
 * - {@see Noop}: nothing to do, such as an event that matched nothing on an untimed wait.
 *
 * Each case carries exactly the fields that mean something for it, so an invalid combination is not
 * representable.
 */
interface Verdict {}
