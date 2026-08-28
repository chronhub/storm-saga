<?php

declare(strict_types=1);

namespace Storm\Saga\Build\Rules;

use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Attributes\State as StateAttribute;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\CorrelationReuse;

/**
 * The topology family of the definition rules: child spawn slots, indexed families, and the
 * correlation-reuse restriction.
 */
final readonly class TopologyRules
{
    /**
     * Every `#[Spawns]` declaration is coherent:
     *
     * - The slot obeys the `ChildCorrelation` grammar, a static identifier and never runtime data;
     *
     * - No slot is declared twice;
     *
     * - The child workflow name is non-empty;
     *
     * - `awaitedBy` names a declared WAIT state of this workflow, since a spawned child concludes
     *   into a wait its parent declares. A completion that would orphan it stays refused at runtime
     *   with `ChildrenStillRunning`, and the declaration is what lets that refusal name the wait that
     *   should have consumed the child.
     *
     * The child TYPE's existence is a registry concern, judged at spawn with `WorkflowNotFound`,
     * since the builder sees one class at a time.
     *
     * @param  list<Spawns>  $spawns
     * @param  list<StateAttribute>  $declared
     *
     * @throws InvalidWorkflowDefinition when a spawn declaration is malformed, duplicated, or awaited by no wait
     */
    public function spawnDeclarationsAreCoherent(string $workflow, array $spawns, array $declared): void
    {
        $seen = [];
        foreach ($spawns as $spawn) {
            if (! ChildCorrelation::isValidSlot($spawn->slot)) {
                throw InvalidWorkflowDefinition::spawnSlotInvalid($spawn->slot, $workflow);
            }
            if (isset($seen[$spawn->slot])) {
                throw InvalidWorkflowDefinition::spawnSlotDuplicated($spawn->slot, $workflow);
            }
            // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset above, so the value is arbitrary
            $seen[$spawn->slot] = true;

            if ($spawn->workflow === '') {
                throw InvalidWorkflowDefinition::spawnChildTypeEmpty($spawn->slot, $workflow);
            }

            $awaited = array_any(
                $declared,
                static fn (StateAttribute $state): bool => $state->key === $spawn->awaitedBy && $state->type === StateType::Wait,
            );
            if (! $awaited) {
                throw InvalidWorkflowDefinition::spawnAwaitedByNotAWait($spawn->awaitedBy, $spawn->slot, $workflow);
            }

            // `slot-<i>` is the member form, and it is read back by ONE grammar wherever a slot is
            // met: the consent proof resolving a member to its declaration, and a settling child
            // deciding whether it can complete a family, with only its own slot in hand. So the
            // suffix is reserved on EVERY slot and not merely on a family's own name. A static slot
            // wearing it would be read as a member of a family that may not even be declared, which
            // is a slot whose meaning depends on what its neighbours happen to be called.
            if (preg_match('/-\d+$/', $spawn->slot) === 1) {
                throw InvalidWorkflowDefinition::spawnSlotEndsInAMemberIndex($spawn->slot, $workflow);
            }
        }

        // consent must be unambiguous AND the family's member scan must be exact: any other slot
        // beginning `family-<digit>` would either give one birth two declarations to answer to, or
        // land inside the family's correlation range and be counted as a member it is not
        foreach ($spawns as $spawn) {
            if (! $spawn->indexed) {
                continue;
            }
            foreach ($spawns as $other) {
                // `preg_quote` is a no-op on every slot that reaches here, the grammar checked at the
                // top of this method admitting `[A-Za-z0-9_-]` alone. It stays because the quoting,
                // not the grammar, is what makes this line correct on its own terms.
                if ($other->slot !== $spawn->slot && preg_match('/^'.preg_quote($spawn->slot, '/').'-\d/', $other->slot) === 1) {
                    throw InvalidWorkflowDefinition::spawnSlotShadowsAFamilyMember($other->slot, $spawn->slot, $workflow);
                }
            }
        }
    }

    /**
     * A workflow that spawns children keeps the default `Reject` reuse. A child correlation is
     * minted from the parent correlation and the slot alone, with no generation, and the family's
     * member scan counts every claim under that prefix: a second run of a reusable parent would
     * remint the first run's children and read their claims as its own, a joined family whose
     * members never ran. Refused where the lineage is declared, so the combination cannot reach a
     * runtime that has no way to tell the runs apart.
     *
     * @param  list<Spawns>  $spawns
     *
     * @throws InvalidWorkflowDefinition when a spawning workflow declares `reuse: Allow`
     */
    public function spawningWorkflowIsNotReusable(string $workflow, array $spawns, CorrelationReuse $reuse): void
    {
        if ($spawns !== [] && $reuse === CorrelationReuse::Allow) {
            throw InvalidWorkflowDefinition::spawnsOnReusableWorkflow($workflow);
        }
    }
}
