<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * What the outbox knows about a dead-lettered command, the settle's pairing input, read back from
 * the stored row by its sealed message id before the step runs, and handed to the policy as a value
 * so the policy stays pure.
 *
 * - `issuedFromState`: the state whose run issued the command, per {@see IssuedCommand}; the settle
 *   requires it to gate the wait the saga rests at, otherwise the dead command is not the one the
 *   wait awaits, an unrelated fire-and-forget, and settling on it would discard a healthy in-flight
 *   effect.
 *
 * - `evidence`: whether anything actually PROVED the effect never committed, stamped on the row by
 *   whichever seam dead-lettered it and read back here, so the cleanup's reconcile hours later
 *   settles on the same footing as the live signal did. See {@see EffectEvidence}.
 *
 * - `hasAliveSiblings`: another command of the same step, same `issued_at_version`, is still
 *   pending or published, so the step's intent was multi-command and the engine cannot know which
 *   one the wait gates; ambiguous, so the settle stands down and escalates, and liveness or at-rest
 *   resolves it.
 *
 * @see StepPolicy the pairing decision
 * @see Engine::failIssuedEffect() where the read happens
 */
final readonly class EffectProvenance
{
    public function __construct(
        public string $issuedFromState,
        public bool $hasAliveSiblings,
        public EffectEvidence $evidence = EffectEvidence::Unknown,
    ) {}
}
