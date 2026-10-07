<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Child\PokeParentFamily;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Signal;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Exception\ChildrenStillRunning;
use Storm\Saga\Exception\ParentNotAdoptable;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Tests\Fixture\AlphaCommand;
use Storm\Saga\Tests\Fixture\RecordingCommandStore;
use Storm\Saga\Tests\Fixture\RecordingStepWrites;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\SpawnSlot;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

use function array_column;
use function array_map;

/**
 * What a settled rest owes beyond its own row, over stubbed stores: the family expectations a
 * fan-out stamps, the adoption a child birth proves, and on a rest that is no longer running the
 * recall, the cascade, the completion guard, the family poke and the timers left behind.
 */
final class StepCommitterSettleTest extends TestCase
{
    private RecordingStepWrites $writes;

    private RecordingCommandStore $store;

    protected function setUp(): void
    {
        $this->writes = new RecordingStepWrites;
        $this->store = new RecordingCommandStore;
    }

    #[Test]
    public function a_fan_out_stamps_each_indexed_family_it_widens_and_nothing_else(): void
    {
        // a plain command and a static slot widen nothing, and the plain command issued first does not
        // end the count; a family whose name reads as a number keeps it as the name the ledger is keyed by
        $row = $this->row('untimed', WorkflowStatus::Running);
        $spawns = [
            new AlphaCommand,
            StartChildWorkflow::with('parent', 'p-1', 'settlement_leg', 'leg-0'),
            StartChildWorkflow::with('parent', 'p-1', 'settlement_leg', 'leg-1'),
            StartChildWorkflow::with('parent', 'p-1', 'batch', '7-0'),
            StartChildWorkflow::with('parent', 'p-1', 'audit', 'manual'),
        ];

        $this->committer()->updated($this->parent(), $row->id(), new Updated($row, [], array_map(
            static fn (object $command): IssuedCommand => new IssuedCommand('act', $command, CommandPurpose::Forward),
            $spawns,
        )), Signal::event(new SampleEvent));

        $this->assertSame(['leg' => 2, 7 => 1], $this->writes->advances[0]['row']->families);
    }

    #[Test]
    public function a_birth_under_a_parent_that_is_gone_is_refused(): void
    {
        $child = new WorkflowInstanceRow('settlement_leg', ChildCorrelation::mint('p-1', 'leg-0'), 'done', WorkflowStatus::Running, context: [
            ParentRef::CONTEXT_KEY => new ParentRef('parent', 'p-1', 'p-1', 'leg-0', 1)->toContext(),
        ]);

        $this->expectException(ParentNotAdoptable::class);

        $this->committer()->created(new WorkflowRegistry([$this->parent()]), $this->plain('settlement_leg'), $child->id(), new Created($child), Signal::start());
    }

    #[Test]
    public function an_aborted_rest_recalls_its_run_s_forward_commands_sparing_what_it_undid_and_cancels_its_children(): void
    {
        $undone = new CompensationRecord('act', CompensationStatus::Compensated, true);
        $row = $this->row('untimed', WorkflowStatus::Halted, [$undone, new CompensationRecord('timed', CompensationStatus::Skipped), new CompensationRecord('tick', CompensationStatus::Pending)]);
        $child = new WorkflowInstanceRow('audit', 'c-1', 'await', WorkflowStatus::Running);

        $this->committer([$child])->updated($this->parent(), $row->id(), new Updated($row), Signal::cancel('operator', true));

        $this->assertSame([['generation' => 3, 'spared' => [$undone]]], array_map(static fn (array $recall): array => ['generation' => $recall['generation'], 'spared' => $recall['spared']], $this->store->recalls));
        $this->assertCount(1, $this->store->written);
        $cascade = $this->store->written[0];
        $this->assertSame(['untimed', 6, 3, CommandPurpose::Control, null], [$cascade['from'], $cascade['version'], $cascade['generation'], $cascade['purpose'], $cascade['group']]);
        $command = $cascade['message']->message();
        $this->assertInstanceOf(CancelChildWorkflow::class, $command);
        $this->assertSame(['parent', 'p-1', 'audit', 'c-1', 'operator', true], [$command->parentWorkflowType, $command->parentCorrelationId, $command->childWorkflowType, $command->childCorrelationId, $command->reason, $command->force]);
    }

    #[Test]
    public function a_running_rest_recalls_nothing_cascades_nothing_and_cancels_nothing(): void
    {
        $row = $this->row('timed', WorkflowStatus::Running);

        $this->committer([new WorkflowInstanceRow('audit', 'c-1', 'await', WorkflowStatus::Running)])
            ->updated($this->parent(), $row->id(), new Updated($row), Signal::event(new SampleEvent));

        $this->assertSame([], $this->store->recalls);
        $this->assertSame([], $this->store->written);
        $this->assertTrue($this->writes->advances[0]['effects']->isEmpty());
    }

    #[Test]
    public function a_completion_with_living_children_rolls_the_step_back_naming_every_awaiting_wait(): void
    {
        $row = $this->row('done', WorkflowStatus::Completed);

        $this->expectException(ChildrenStillRunning::class);
        $this->expectExceptionMessageMatches('/with 1 living child.*leg → awaited by "untimed".*manual → awaited by "timed"/s');

        $this->committer([new WorkflowInstanceRow('audit', 'c-1', 'await', WorkflowStatus::Running)])
            ->updated($this->parent(), $row->id(), new Updated($row), Signal::event(new SampleEvent));
    }

    #[Test]
    public function a_completion_with_no_living_child_settles_without_a_recall(): void
    {
        $row = $this->row('done', WorkflowStatus::Completed);

        $this->committer()->updated($this->parent(), $row->id(), new Updated($row), Signal::event(new SampleEvent));

        $this->assertSame([], $this->store->recalls);
        $this->assertCount(1, $this->writes->advances);
    }

    #[Test]
    public function a_family_member_s_terminal_rest_pokes_its_parent_as_control_traffic(): void
    {
        $member = $this->member('leg-0', WorkflowStatus::Completed);

        $this->committer()->updated($this->plain('settlement_leg'), $member->id(), new Updated($member), Signal::event(new SampleEvent));

        $this->assertCount(1, $this->store->written);
        $poke = $this->store->written[0];
        $this->assertSame(['done', 5, 1, CommandPurpose::Control], [$poke['from'], $poke['version'], $poke['generation'], $poke['purpose']]);
        $command = $poke['message']->message();
        $this->assertInstanceOf(PokeParentFamily::class, $command);
        $this->assertSame(['parent', 'p-1', 'settlement_leg', $member->correlationId], [$command->parentWorkflowType, $command->parentCorrelationId, $command->childWorkflowType, $command->childCorrelationId]);
    }

    #[Test]
    public function neither_a_static_child_nor_a_running_member_pokes_its_parent(): void
    {
        $static = $this->member('manual', WorkflowStatus::Completed);
        $running = $this->member('leg-1', WorkflowStatus::Running);

        $this->committer()->updated($this->plain('settlement_leg'), $static->id(), new Updated($static), Signal::event(new SampleEvent));
        $this->committer()->updated($this->plain('settlement_leg'), $running->id(), new Updated($running), Signal::event(new SampleEvent));

        $this->assertSame([], $this->store->written);
    }

    /**
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function restingStates(): iterable
    {
        yield 'an activity' => ['act', true, ['act', '__global__']];
        yield 'a timed wait' => ['timed', true, ['timed', '__global__']];
        yield 'a schedule' => ['tick', true, ['tick', '__global__']];
        yield 'an untimed wait' => ['untimed', true, ['__global__']];
        yield 'a final state' => ['done', true, ['__global__']];
        yield 'an activity without a cap' => ['act', false, ['act']];
    }

    /**
     * @param  list<string>  $cancelled
     */
    #[Test]
    #[DataProvider('restingStates')]
    public function a_halted_rest_cancels_only_the_timers_that_could_exist(string $state, bool $capped, array $cancelled): void
    {
        // a halt in place strands the resting state's timer only where that state arms one, and the
        // global deadline only where the workflow declares one
        $row = $this->row($state, WorkflowStatus::Halted);

        $this->committer()->updated($this->parent($capped), $row->id(), new Updated($row), Signal::event(new SampleEvent));

        $this->assertSame($cancelled, array_column($this->writes->advances[0]['effects']->cancels, 'stateKey'));
    }

    /**
     * @param  list<WorkflowInstanceRow>  $living
     */
    private function committer(array $living = []): StepCommitter
    {
        $instances = $this->createStub(WorkflowInstances::class);
        $instances->method('create')->willReturn(1);
        $families = $this->createStub(WorkflowFamilies::class);
        $families->method('livingChildren')->willReturn($living);

        return new StepCommitter(
            $instances,
            $families,
            $this->createStub(WorkflowTimers::class),
            new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->store),
            FrozenClock::at('2026-09-25T10:00:00.000000+00:00'),
            null,
            $this->writes,
        );
    }

    /**
     * @param  list<CompensationRecord>  $compensations
     */
    private function row(string $state, WorkflowStatus $status, array $compensations = []): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('parent', 'p-1', $state, $status, version: 5, compensations: $compensations, generation: 3);
    }

    private function member(string $slot, WorkflowStatus $status): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('settlement_leg', ChildCorrelation::mint('p-1', $slot), 'done', $status, context: [
            ParentRef::CONTEXT_KEY => new ParentRef('parent', 'p-1', 'p-1', $slot, 1)->toContext(),
        ], version: 4);
    }

    private function parent(bool $capped = true): WorkflowDefinition
    {
        // the slot named `7` keys as an int at runtime, exactly as the builder's own map would
        /** @var array<string, SpawnSlot> $spawns */
        $spawns = [
            'leg' => new SpawnSlot('leg', 'settlement_leg', 'untimed', indexed: true),
            '7' => new SpawnSlot('7', 'batch', 'untimed', indexed: true),
            'manual' => new SpawnSlot('manual', 'audit', 'timed'),
        ];

        return new WorkflowDefinition('parent', [
            'act' => new ActivityState('act', $this->activity(), transitions: [new Transition(OnTrigger::Success, 'timed')]),
            'timed' => new WaitState('timed', eventClasses: [SampleEvent::class], timeout: new Timeout(60), transitions: [new Transition(OnTrigger::Event, 'untimed')]),
            'untimed' => new WaitState('untimed', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'tick' => new ScheduleState('tick', null, new CatchUpPolicy(CatchUp::Skip)),
            'done' => new FinalState('done'),
        ], 'act', globalTimeout: $capped ? 3600 : null, spawns: $spawns);
    }

    private function plain(string $name): WorkflowDefinition
    {
        return new WorkflowDefinition($name, ['done' => new FinalState('done')], 'done');
    }

    private function activity(): Activity
    {
        return new class() implements Activity
        {
            public function run(array $vars, Metadata $metadata): ActivityResult
            {
                return ActivityResult::success();
            }
        };
    }
}
