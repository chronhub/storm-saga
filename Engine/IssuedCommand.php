<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * A command the machine asked to issue, with its provenance: the state whose run produced it. The
 * attribution is collected where it is known for free; the cursor wraps a verdict's commands with
 * the state that yielded the verdict, and the compensator wraps them with the compensated step. It
 * then rides to the outbox row `issued_from_state`, where it becomes the settle's pairing input: a
 * dead-lettered command settles the saga only when the state that issued it gates the wait the saga
 * rests at.
 *
 * @see MachineCursor where the forward path wraps
 * @see Compensator where the rollback path wraps
 * @see EffectProvenance the read-side twin, derived back from the stored row
 */
final readonly class IssuedCommand
{
    /**
     * @param  string|null  $effectGroup  the concurrent arm this command belongs to, the targeted
     *                                    recall's key; null for the ungrouped common case
     */
    public function __construct(
        public string $fromState,
        public object $command,
        public ?string $effectGroup = null,
    ) {}
}
