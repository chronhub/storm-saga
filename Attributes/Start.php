<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * The workflow's entry state; `Engine::start()` begins here.
 *
 * `$afterSeconds` declares a birth delay: nothing the workflow does runs before the due. Its
 * semantics are deliberate and narrow:
 *
 * - The saga is BORN immediately: the row, the correlation claim, the fence and the dedup all
 *   land, so the run identity is exactly a plain birth's. Only the FIRST DRIVE is deferred, as a
 *   kick armed `afterSeconds` from birth that re-runs the start state with no stimulus, the very
 *   drive a birth performs.
 *
 * - The `globalTimeout` anchors at BIRTH and INCLUDES the delay: the cap is hard, and a delay is
 *   never extra time the business did not grant. A kick landing past the cap enforces the global
 *   deadline instead of driving; the build refuses `afterSeconds >= globalTimeout` outright, a
 *   saga that would expire before it ever runs.
 *
 * - An event delivered during the delay is EARLY, never a wake: the policy skips it as
 *   `BirthDelayPending`, the engine reports it not yet applicable, and the transport redelivers
 *   until the due has passed. From the due on the wake contract resumes: an event landing before
 *   the kick performs the deferred drive itself, and the kick behaves as any raced kick. Cancel
 *   and the global deadline pass through the delay untouched.
 *
 * - A signal delivered during the delay lands its vars and issues its commands as on any resting
 *   saga; the delay holds, untouched. `startOrSignal()` composes with no special case: the birth
 *   defers, the signal applies.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Start
{
    public function __construct(
        public string $state,
        public ?int $afterSeconds = null,
    ) {}
}
