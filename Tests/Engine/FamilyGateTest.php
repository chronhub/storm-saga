<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\FamilyGate;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Event\SagaFamilyMemberConcluded;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\StubEventResolver;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\SpawnSlot;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The completeness gate over stubbed member counts: WHICH families a resting wait is judged
 * against, what a conclusion arriving before they are done leaves behind, and the poke's own
 * question asked without an event. What the counts mean against the real member registry, and the
 * poke that spends what a rest parks, are proved end to end in the integration suite.
 *
 * The fixture graph declares three spawn slots on purpose, since the three conditions that select a
 * family are independent: `leg` is an indexed family awaited HERE, `audit` is an indexed family
 * awaited by a DIFFERENT wait, and `manual` is a static slot awaited here that gates nothing.
 */
final class FamilyGateTest extends TestCase
{
    #[Test]
    public function every_expected_member_spawned_and_none_living_is_ready(): void
    {
        $gate = $this->gate(['leg' => [3, 0]]);

        $this->assertTrue($gate->readyToCross($this->def(), $this->row(['leg' => 3])));
    }

    #[Test]
    public function a_member_still_running_is_not_ready(): void
    {
        $gate = $this->gate(['leg' => [3, 1]]);

        $this->assertFalse($gate->readyToCross($this->def(), $this->row(['leg' => 3])));
    }

    #[Test]
    public function a_birth_still_owed_is_not_ready_whatever_the_living_count(): void
    {
        // the missing-spawn path: with a member never minted the family is not in its endgame at all,
        // so no settle can complete it and the wait's own heartbeat stays the witness
        $gate = $this->gate(['leg' => [2, 0]]);

        $this->assertFalse($gate->readyToCross($this->def(), $this->row(['leg' => 3])));
    }

    #[Test]
    public function a_wait_that_stamped_no_family_is_ready_and_reads_no_counts(): void
    {
        // it never gated, so it owes nothing; the caller's park check is what decides there is
        // anything to spend, and no count is read to answer
        $gate = $this->gate([]);

        $this->assertTrue($gate->readyToCross($this->def(), $this->row([])));
    }

    #[Test]
    public function only_an_indexed_family_this_very_wait_awaits_is_judged(): void
    {
        // three slots, one answer. `audit` is a family of this workflow but another wait's business,
        // and `manual` is a static slot: it awaits here and has an expectation stamped, yet it gates
        // nothing, since a static child's conclusion crosses on arrival. Judging either would rest a
        // saga on a family that was never this wait's to wait for.
        $gate = $this->gate(['leg' => [1, 0], 'audit' => [1, 1], 'manual' => [1, 1]]);

        $this->assertTrue($gate->readyToCross($this->def(), $this->row(['leg' => 1, 'audit' => 1, 'manual' => 1])));
    }

    #[Test]
    public function every_awaited_family_is_judged_and_not_merely_the_first(): void
    {
        // the aggregate rule: one wait may await several families and a family reaching its own last
        // member says NOTHING about whether the wait can cross. The complete one comes first here on
        // purpose, since declaration order is what a short-circuit would silently follow.
        $gate = $this->gate(['leg' => [1, 0], 'second' => [2, 1]]);
        $row = $this->row(['leg' => 1, 'second' => 2]);

        $this->assertFalse($gate->readyToCross($this->defWithSecondFamily(), $row));
    }

    #[Test]
    public function a_conclusion_arriving_early_rests_in_place_parked_and_announces_each_family_that_holds_the_gate(): void
    {
        // the vars land exactly as the crossing would land them, the event itself goes nowhere, and
        // what is left behind is the crossing owed back. One announcement PER family still out, since
        // a quiet family must say why it waits and two of them are two reasons.
        $gate = $this->gate(['leg' => [1, 1], 'second' => [2, 1]]);
        $row = $this->row(['leg' => 1, 'second' => 2]);

        $rested = $gate->gateConclusion($this->defWithSecondFamily(), $row, Stimulus::event(new SampleEvent), 'cause-1');

        $this->assertInstanceOf(Rested::class, $rested);
        $this->assertSame('await_legs', $rested->row->stateKey);
        $this->assertTrue($rested->row->vars['leg_seen']);
        $this->assertSame(['state' => 'await_legs', 'event' => SampleEvent::class, 'cause' => 'cause-1'], $rested->row->parked);
        $families = [];
        foreach ($rested->announcements as $announcement) {
            $this->assertInstanceOf(SagaFamilyMemberConcluded::class, $announcement);
            $families[] = $announcement->family;
        }
        $this->assertSame(['leg', 'second'], $families);
    }

    #[Test]
    public function a_conclusion_arriving_with_every_family_complete_is_handed_to_the_machine(): void
    {
        $gate = $this->gate(['leg' => [1, 0]]);

        $this->assertNull($gate->gateConclusion($this->def(), $this->row(['leg' => 1]), Stimulus::event(new SampleEvent), null));
    }

    #[Test]
    public function an_event_the_wait_refuses_is_handed_to_the_machine_untouched(): void
    {
        // the machine will Noop on it identically, and resting it here would park a crossing on a
        // class the wait cannot route
        $gate = $this->gate(['leg' => [1, 1]]);

        $this->assertNull($gate->gateConclusion($this->def(), $this->row(['leg' => 1]), Stimulus::event(new stdClass), null));
    }

    #[Test]
    public function a_stimulus_carrying_no_event_gates_nothing(): void
    {
        // a fired timer, a retry kick or a replayed crossing: the gate judges arrivals alone
        $gate = $this->gate(['leg' => [1, 1]]);

        $this->assertNull($gate->gateConclusion($this->def(), $this->row(['leg' => 1]), Stimulus::timeout(), null));
    }

    /**
     * @param  array<string, array{int, int}>  $counts  spawned and living, per family; a family absent
     *                                                  from the map must never be read
     */
    private function gate(array $counts): FamilyGate
    {
        $instances = new class($counts) implements WorkflowFamilies
        {
            /**
             * @param  array<string, array{int, int}>  $counts
             */
            public function __construct(private readonly array $counts) {}

            public function spawnedMembers(string $parentCorrelationId, string $family): int
            {
                return $this->of($family)[0];
            }

            public function livingMembers(string $parentCorrelationId, string $family): int
            {
                return $this->of($family)[1];
            }

            public function countChildren(string $parentCorrelationId): int
            {
                return 0;
            }

            public function countChildrenSerialized(string $parentCorrelationId): int
            {
                return 0;
            }

            public function livingChildren(string $parentCorrelationId): array
            {
                return [];
            }

            public function loadAdoptableParent(string $correlationId): ?WorkflowInstanceRow
            {
                return null;
            }

            /**
             * @return array{int, int}
             */
            private function of(string $family): array
            {
                return $this->counts[$family]
                    ?? throw new LogicException(sprintf('the gate read counts for "%s", a family it was not meant to judge', $family));
            }
        };

        return new FamilyGate($instances, new WaitVarExtractor(new StubEventResolver));
    }

    private function def(): WorkflowDefinition
    {
        return $this->definition([
            'leg' => new SpawnSlot('leg', 'settlement_leg', 'await_legs', indexed: true),
            'audit' => new SpawnSlot('audit', 'settlement_leg', 'elsewhere', indexed: true),
            'manual' => new SpawnSlot('manual', 'settlement_leg', 'await_legs'),
        ]);
    }

    private function defWithSecondFamily(): WorkflowDefinition
    {
        return $this->definition([
            'leg' => new SpawnSlot('leg', 'settlement_leg', 'await_legs', indexed: true),
            'second' => new SpawnSlot('second', 'settlement_leg', 'await_legs', indexed: true),
        ]);
    }

    /**
     * @param  array<string, SpawnSlot>  $spawns
     */
    private function definition(array $spawns): WorkflowDefinition
    {
        return new WorkflowDefinition(
            'settlement',
            [
                'await_legs' => new WaitState(
                    'await_legs',
                    eventClasses: [SampleEvent::class],
                    extract: static fn (object $event, array $vars): array => ['leg_seen' => true],
                    transitions: [new Transition(OnTrigger::Event, 'done')],
                ),
                'elsewhere' => new WaitState('elsewhere', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
                'done' => new FinalState('done'),
            ],
            'await_legs',
            spawns: $spawns,
        );
    }

    /**
     * @param  array<string, int>  $families
     */
    private function row(array $families): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('settlement', 's-1', 'await_legs', WorkflowStatus::Running, families: $families);
    }
}
