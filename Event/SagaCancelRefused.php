<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * The engine refused a cancel: the saga sits at an effect-gating wait and the signal carries no
 * `force`, so invariant 2 holds; an in-flight effect is never discarded on an operator's word
 * alone, and a parent's nominal cascade gets the same answer. The saga SURVIVES the cancel; this
 * announcement is what keeps that survival from being silent. Without it the refusal is one more
 * indistinguishable `false`, and a child parked here outlives its aborting parent unseen until the
 * zombie sweep lists it.
 *
 * The one `Skip` with a voice: every other skip reason is a benign race both sides are designed to
 * absorb quietly, but a refusal leaves a LIVING saga behind that someone asked to die: retry after
 * the outcome lands, or own the risk explicitly with `force`.
 *
 * `$state` is the gating wait the saga is parked at; `$reason` is the canceller's word, carried for
 * the trail exactly as `SagaCancelled` carries it on the applied path.
 *
 * Telemetry only.
 */
final readonly class SagaCancelRefused implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $state,
        public ?string $reason = null,
    ) {}
}
