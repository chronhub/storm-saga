<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Saga\Engine\SagaFamilyTarget;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The receiving side of the poke: proves the claim, then asks the engine to spend whatever crossing
 * the parent's family gate rested. Nothing is decided here; the engine re-reads the parent's row and
 * the member counts under the fence, so a poke is advisory and a stale one costs a lookup.
 *
 * The proof is STRUCTURAL and needs no read, which is what makes it total: a member's correlation is
 * minted from its parent's and its slot, so a command whose child does not spell out this parent, or
 * whose slot is not a family member's, cannot have come from a settling member. That is corruption,
 * not weather, and it surfaces as such rather than nudging some other saga on the strength of a
 * correlation it carries. The reads that WOULD be racy, whether the parent still lives and whether
 * its families are complete, are exactly the ones deferred to the fenced step.
 *
 * @see PokeParentFamily
 */
final readonly class FamilyPoker
{
    public function __construct(
        private SagaFamilyTarget $engine,
    ) {}

    /**
     * @return bool true when the parent spent a parked crossing; false when it owed none, had moved
     *              on from the wait it parked at, or no longer exists
     *
     * @throws InvalidChildIdentity when the command's child is not a family member of the parent it names
     * @throws SagaFenceBusy when a concurrent step holds the parent's fence; retry the command
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function poke(PokeParentFamily $command): bool
    {
        $minted = ChildCorrelation::split($command->childCorrelationId);

        if ($minted['parentCorrelationId'] !== $command->parentCorrelationId) {
            throw InvalidChildIdentity::correlationMismatch(
                ChildCorrelation::mint($command->parentCorrelationId, $minted['slot']),
                $command->childCorrelationId,
            );
        }

        if (ChildCorrelation::familyOfSlot($minted['slot']) === null) {
            throw InvalidChildIdentity::invalidSlot($minted['slot']);
        }

        return $this->engine->pokeFamily($command->parentWorkflowType, $command->parentCorrelationId);
    }
}
