<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * One declared spawn slot of a workflow definition, built from `#[Spawns]`: the slot name, the
 * child workflow type the parent consents to under it, and the wait state that consumes the
 * child's conclusion. The adoption proof reads this at birth: a slot absent from the parent's
 * pinned definition, or carrying another child type, is refused as poison, never as a race.
 */
final readonly class SpawnSlot
{
    public function __construct(
        public string $slot,
        public string $workflow,
        public string $awaitedBy,
        /** True for a slot FAMILY: members mint as `slot-<i>`, consent matches by prefix. */
        public bool $indexed = false,
    ) {}
}
