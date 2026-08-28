<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Child;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\FamilyPoker;
use Storm\Saga\Child\PokeParentFamily;
use Storm\Saga\Engine\SagaFamilyTarget;
use Storm\Saga\Exception\InvalidChildIdentity;

/**
 * The poke's receiving side decides nothing and proves one thing: that the command came from a
 * member of the family it claims. The proof is structural, so it needs no read and cannot race; the
 * questions that WOULD race, whether the parent lives and whether its families are complete, belong
 * to the fenced step and are asserted against the database in `FamilyFanOutTest`.
 */
final class FamilyPokerTest extends TestCase
{
    #[Test]
    public function a_members_poke_reaches_the_parent_it_names(): void
    {
        $engine = $this->engine(true);
        $poker = new FamilyPoker($engine);

        $spent = $poker->poke(PokeParentFamily::with('settlement', 's-1', 'settlement_leg', ChildCorrelation::mint('s-1', 'leg-3')));

        $this->assertTrue($spent);
        $this->assertSame([['settlement', 's-1']], $engine->poked);
    }

    #[Test]
    public function a_parent_that_owes_nothing_answers_false_without_ceremony(): void
    {
        $poker = new FamilyPoker($this->engine(false));

        $this->assertFalse($poker->poke(PokeParentFamily::with('settlement', 's-1', 'settlement_leg', ChildCorrelation::mint('s-1', 'leg-0'))));
    }

    #[Test]
    public function a_poke_whose_child_is_not_this_parents_is_corruption_not_weather(): void
    {
        // a member's correlation is MINTED from its parent's, so a command that spells out another
        // parent cannot have come from a settling member of this one. Nudging some saga on the
        // strength of a correlation the command merely carries is how an unrelated workflow moves.
        $engine = $this->engine(true);
        $poker = new FamilyPoker($engine);

        $this->expectException(InvalidChildIdentity::class);

        try {
            $poker->poke(PokeParentFamily::with('settlement', 's-1', 'settlement_leg', ChildCorrelation::mint('s-other', 'leg-0')));
        } finally {
            $this->assertSame([], $engine->poked); // nothing reached the engine
        }
    }

    #[Test]
    public function a_poke_from_a_static_slot_is_corruption_too(): void
    {
        // only an indexed family's gate parks a crossing, so only a member slot can owe one; a static
        // slot reaching here means the settle's narrowing was widened without this proof moving
        $poker = new FamilyPoker($this->engine(true));

        $this->expectException(InvalidChildIdentity::class);

        $poker->poke(PokeParentFamily::with('onboarding', 'ident-1', 'kyc_review', ChildCorrelation::mint('ident-1', 'kyc')));
    }

    #[Test]
    public function a_poke_naming_a_correlation_outside_the_child_namespace_is_refused(): void
    {
        $poker = new FamilyPoker($this->engine(true));

        $this->expectException(InvalidChildIdentity::class);

        $poker->poke(PokeParentFamily::with('settlement', 's-1', 'settlement_leg', 'not-a-child'));
    }

    /**
     * @return SagaFamilyTarget&object{poked: list<array{string, string}>}
     */
    private function engine(bool $spends): object
    {
        return new class($spends) implements SagaFamilyTarget
        {
            /** @var list<array{string, string}> */
            public array $poked = [];

            public function __construct(private readonly bool $spends) {}

            public function pokeFamily(string $workflowType, string $correlationId, ?string $causationId = null): bool
            {
                $this->poked[] = [$workflowType, $correlationId];

                return $this->spends;
            }
        };
    }
}
