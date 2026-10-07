<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use Closure;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\FrozenClock;
use Storm\Clock\PointInTime;
use Storm\Message\ContextValues;
use Storm\Message\Header;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\Outcome\Created;
use Storm\Saga\Engine\Outcome\Updated;
use Storm\Saga\Engine\Signal;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Exception\BusinessCalendarMissing;
use Storm\Saga\Exception\MalformedJoinFanOut;
use Storm\Saga\Exception\MalformedRaceFanOut;
use Storm\Saga\Exception\UnattributedJoinCommand;
use Storm\Saga\Exception\UnattributedRaceCommand;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\OutboxEntry;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Tests\Fixture\AlphaCommand;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\BetaCommand;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\RecordingCommandStore;
use Storm\Saga\Tests\Fixture\RecordingStepWrites;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

use function array_map;
use function restore_error_handler;
use function set_error_handler;

/**
 * What a committer settles for the writer, over stubbed stores: each timer instruction resolved to
 * its instant exactly once, and each issued command sealed with its provenance, a fan-out's arm
 * included, before a single row is written. The rest a step owes is proved beside it.
 */
final class StepCommitterEffectsTest extends TestCase
{
    private const string NOW = '2026-09-25T10:00:00.000000+00:00';

    #[Test]
    public function a_birth_resolves_every_timer_instruction_and_seals_its_commands_for_the_run_it_claimed(): void
    {
        $writes = new RecordingStepWrites;
        $slot = PointInTime::from('2026-09-30T08:00:00.000000+00:00');
        $row = new WorkflowInstanceRow('plain', 'p-1', 'wait', WorkflowStatus::Running);

        $born = $this->committer($writes)->created(new WorkflowRegistry([$this->plain()]), $this->plain(), $row->id(), new Created($row, [
            TimerOp::armTimeout('wait', 300),
            TimerOp::armTimeout('zero', 0),
            TimerOp::cancelGlobal(),
            TimerOp::armGlobal(3600),
            TimerOp::armKick('late', 1200),
            TimerOp::armKick('second', 1000),
            TimerOp::armKick('past', 1001),
            TimerOp::armKick('now', null),
            TimerOp::armKick('instant', 0),
            TimerOp::armScheduleAt('slot', $slot),
            TimerOp::cancelState('left'),
        ], [new IssuedCommand('wait', new AlphaCommand, CommandPurpose::Forward)]), Signal::start());

        $this->assertSame(4, $born->generation);
        $this->assertCount(1, $writes->applied);
        $this->assertEquals($row->id(), $writes->applied[0]['id']);

        $effects = $writes->applied[0]['effects'];
        $global = TimerOp::cancelGlobal()->stateKey;
        $this->assertSame([
            ['stateKey' => $global, 'keepKinds' => [TimerKind::Global->value]],
            ['stateKey' => 'left', 'keepKinds' => []],
        ], $effects->cancels);
        // an instant resolved from the clock: seconds floored to one, a kick's milliseconds rounded
        // UP to whole seconds and a kick with no delay firing now, a schedule's instant armed as given
        $this->assertSame([
            ['wait', TimerKind::Timeout, $this->at(300)],
            ['zero', TimerKind::Timeout, $this->at(1)],
            [$global, TimerKind::Global, $this->at(3600)],
            ['late', TimerKind::Kick, $this->at(2)],
            ['second', TimerKind::Kick, $this->at(1)],
            ['past', TimerKind::Kick, $this->at(2)],
            ['now', TimerKind::Kick, $this->at(0)],
            ['instant', TimerKind::Kick, $this->at(1)],
            ['slot', TimerKind::Schedule, $slot->toString()],
        ], array_map(static fn (array $arm): array => [$arm['stateKey'], $arm['kind'], $arm['fireAt']->toString()], $effects->arms));

        $this->assertSame([['wait', 0, 4, CommandPurpose::Forward, null, AlphaCommand::class, 'p-1']], $this->entries($writes->applied[0]['commands']));
    }

    #[Test]
    #[DataProvider('advanceVersions')]
    public function an_advance_preserves_the_loaded_row_and_stamps_every_command_with_the_committed_version(int $loadedVersion, int $committedVersion): void
    {
        $writes = new RecordingStepWrites;
        $row = new WorkflowInstanceRow('plain', 'p-1', 'wait', WorkflowStatus::Running, version: $loadedVersion, generation: 2);

        $this->committer($writes)->updated($this->plain(), $row->id(), new Updated($row, [], [
            new IssuedCommand('wait', new AlphaCommand, CommandPurpose::Forward),
            new IssuedCommand('wait', new BetaCommand, CommandPurpose::Forward),
        ]), Signal::event(new SampleEvent));

        $this->assertSame([], $writes->applied);
        $this->assertCount(1, $writes->advances);
        $this->assertSame($row, $writes->advances[0]['row']);
        $this->assertTrue($writes->advances[0]['effects']->isEmpty());
        $this->assertSame([
            ['wait', $committedVersion, 2, CommandPurpose::Forward, null, AlphaCommand::class, 'p-1'],
            ['wait', $committedVersion, 2, CommandPurpose::Forward, null, BetaCommand::class, 'p-1'],
        ], $this->entries($writes->advances[0]['commands']));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function advanceVersions(): iterable
    {
        yield 'first advance after birth' => [0, 1];
        yield 'later advance' => [7, 8];
    }

    #[Test]
    public function a_business_deadline_advances_through_the_calendar_from_now(): void
    {
        $due = PointInTime::from('2026-09-29T09:00:00.000000+00:00');
        $asked = [];
        $calendar = $this->createStub(BusinessCalendar::class);
        $calendar->method('advance')->willReturnCallback(static function (PointInTime $from, int $days, int $hours) use (&$asked, $due): PointInTime {
            $asked[] = [$from->toString(), $days, $hours];

            return $due;
        });
        $writes = new RecordingStepWrites;
        $row = new WorkflowInstanceRow('plain', 'p-1', 'wait', WorkflowStatus::Running);

        $this->committer($writes, $calendar)->updated($this->plain(), $row->id(), new Updated($row, [
            TimerOp::armBusinessTimeout('wait', 2, null),
            TimerOp::armBusinessTimeout('other', null, 5),
        ]), Signal::event(new SampleEvent));

        $this->assertSame([[self::NOW, 2, 0], [self::NOW, 0, 5]], $asked);
        $this->assertSame([$due->toString(), $due->toString()], array_map(static fn (array $arm): string => $arm['fireAt']->toString(), $writes->advances[0]['effects']->arms));
    }

    #[Test]
    public function a_business_deadline_without_a_calendar_is_refused(): void
    {
        $row = new WorkflowInstanceRow('plain', 'p-1', 'wait', WorkflowStatus::Running);

        $this->expectException(BusinessCalendarMissing::class);

        $this->committer(new RecordingStepWrites)->updated($this->plain(), $row->id(), new Updated($row, [TimerOp::armBusinessTimeout('wait', 1, null)]), Signal::event(new SampleEvent));
    }

    #[Test]
    public function a_race_fan_out_stamps_each_command_with_its_arm(): void
    {
        $this->assertSame(['alpha', 'beta'], $this->armsOf($this->race(), 'quote', [new AlphaCommand, new BetaCommand]));
    }

    #[Test]
    public function a_join_fan_out_stamps_each_command_with_its_arm(): void
    {
        $this->assertSame(['alpha', 'beta'], $this->armsOf($this->join(), 'checks', [new AlphaCommand, new BetaCommand]));
    }

    #[Test]
    public function a_disposition_keeps_its_own_arm_and_is_not_judged_as_a_fan_out(): void
    {
        // a settler's undo issues from the fan-out state stamped with the arm it disposes of, and its
        // command is the undo's own, owned by no arm: one command, never the width of the fan-out
        $writes = new RecordingStepWrites;
        $row = new WorkflowInstanceRow('race', 'r-1', 'await', WorkflowStatus::Running);

        $this->within(fn () => $this->committer($writes)->updated($this->race(), $row->id(), new Updated($row, [], [new IssuedCommand('quote', new stdClass, CommandPurpose::Compensation, 'beta')]), Signal::event(new SampleEvent)));

        $this->assertSame([['quote', 1, 1, CommandPurpose::Compensation, 'beta', stdClass::class, 'r-1']], $this->entries($writes->advances[0]['commands']));
    }

    #[Test]
    public function a_command_from_a_state_outside_any_fan_out_carries_no_arm(): void
    {
        $this->assertSame([null, null], $this->armsOf($this->race(), 'await', [new AlphaCommand, new stdClass]));
        $this->assertSame([null], $this->armsOf($this->race(), 'undeclared', [new stdClass]));
    }

    #[Test]
    public function a_disposition_issued_first_does_not_hide_the_fan_out_that_follows(): void
    {
        $this->expectException(MalformedRaceFanOut::class);

        $this->issue($this->race(), [
            new IssuedCommand('quote', new stdClass, CommandPurpose::Compensation, 'beta'),
            new IssuedCommand('quote', new AlphaCommand, CommandPurpose::Forward),
        ]);
    }

    #[Test]
    public function a_command_from_another_state_issued_first_does_not_hide_the_fan_out_that_follows(): void
    {
        $this->expectException(MalformedRaceFanOut::class);

        $this->issue($this->race(), [
            new IssuedCommand('await', new stdClass, CommandPurpose::Forward),
            new IssuedCommand('quote', new AlphaCommand, CommandPurpose::Forward),
        ]);
    }

    #[Test]
    public function a_race_fan_out_missing_an_arm_is_refused(): void
    {
        $this->expectException(MalformedRaceFanOut::class);
        $this->expectExceptionMessageMatches('/beta/');

        $this->armsOf($this->race(), 'quote', [new AlphaCommand]);
    }

    #[Test]
    public function a_race_fan_out_doubling_an_arm_is_refused(): void
    {
        $this->expectException(MalformedRaceFanOut::class);
        $this->expectExceptionMessageMatches('/alpha/');

        $this->armsOf($this->race(), 'quote', [new AlphaCommand, new AlphaCommand, new BetaCommand]);
    }

    #[Test]
    public function a_race_state_issuing_a_command_no_arm_owns_is_refused(): void
    {
        $this->expectException(UnattributedRaceCommand::class);

        $this->armsOf($this->race(), 'quote', [new AlphaCommand, new BetaCommand, new stdClass]);
    }

    #[Test]
    public function a_join_fan_out_missing_an_arm_is_refused(): void
    {
        $this->expectException(MalformedJoinFanOut::class);
        $this->expectExceptionMessageMatches('/alpha/');

        $this->armsOf($this->join(), 'checks', [new BetaCommand]);
    }

    #[Test]
    public function a_join_fan_out_doubling_an_arm_is_refused(): void
    {
        $this->expectException(MalformedJoinFanOut::class);
        $this->expectExceptionMessageMatches('/beta/');

        $this->armsOf($this->join(), 'checks', [new AlphaCommand, new BetaCommand, new BetaCommand]);
    }

    #[Test]
    public function a_join_state_issuing_a_command_no_arm_owns_is_refused(): void
    {
        $this->expectException(UnattributedJoinCommand::class);

        $this->armsOf($this->join(), 'checks', [new AlphaCommand, new BetaCommand, new stdClass]);
    }

    private function committer(RecordingStepWrites $writes, ?BusinessCalendar $calendar = null): StepCommitter
    {
        $instances = $this->createStub(WorkflowInstances::class);
        $instances->method('create')->willReturn(4);

        return new StepCommitter(
            $instances,
            $this->createStub(WorkflowFamilies::class),
            $this->createStub(WorkflowTimers::class),
            new WorkflowOutbox(new HopProtocol(ContextValues::empty()), new RecordingCommandStore),
            FrozenClock::at(self::NOW),
            $calendar,
            $writes,
        );
    }

    /**
     * The arm each sealed command carries, for commands issued from `$state` in one advance.
     *
     * @param  list<object>  $commands
     * @return list<string|null>
     */
    private function armsOf(WorkflowDefinition $def, string $state, array $commands): array
    {
        $writes = $this->issue($def, array_map(static fn (object $command): IssuedCommand => new IssuedCommand($state, $command, CommandPurpose::Forward), $commands));

        return array_map(static fn (OutboxEntry $entry): ?string => $entry->effectGroup, $writes->advances[0]['commands']);
    }

    /**
     * One advance issuing `$issued`, run under the warning-to-exception handler.
     *
     * @param  list<IssuedCommand>  $issued
     */
    private function issue(WorkflowDefinition $def, array $issued): RecordingStepWrites
    {
        $writes = new RecordingStepWrites;
        $row = new WorkflowInstanceRow($def->name, 'f-1', 'await', WorkflowStatus::Running);

        $this->within(fn () => $this->committer($writes)->updated($def, $row->id(), new Updated($row, [], $issued), Signal::event(new SampleEvent)));

        return $writes;
    }

    /**
     * Runs `$step` under an error handler that turns a PHP warning into an exception, as the
     * framework's debug handler does, so a read the committer is not meant to make fails here.
     *
     * @param  Closure(): mixed  $step
     */
    private function within(Closure $step): void
    {
        set_error_handler(static fn (int $severity, string $message, string $file, int $line): never => throw new ErrorException($message, 0, $severity, $file, $line));

        try {
            $step();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  list<OutboxEntry>  $entries
     * @return list<array{string, int, int, CommandPurpose, string|null, mixed, mixed}>
     */
    private function entries(array $entries): array
    {
        return array_map(static fn (OutboxEntry $entry): array => [
            $entry->issuedFromState,
            $entry->issuedAtVersion,
            $entry->generation,
            $entry->purpose,
            $entry->effectGroup,
            $entry->message->header(Header::MessageType),
            $entry->message->header(Header::CorrelationId),
        ], $entries);
    }

    /**
     * @param  int<0, max>  $seconds
     */
    private function at(int $seconds): string
    {
        $now = FrozenClock::at(self::NOW)->now();

        return ($seconds === 0 ? $now : $now->addSeconds($seconds))->toString();
    }

    private function plain(): WorkflowDefinition
    {
        return new WorkflowDefinition('plain', [
            'wait' => new WaitState('wait', eventClasses: [SampleEvent::class], transitions: [new Transition(OnTrigger::Event, 'done')]),
            'done' => new FinalState('done'),
        ], 'wait');
    }

    private function race(): WorkflowDefinition
    {
        $workflow = new #[Workflow(name: 'race')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        return $this->builder()->build($workflow);
    }

    private function join(): WorkflowDefinition
    {
        $workflow = new #[Workflow(name: 'join')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        return $this->builder()->build($workflow);
    }

    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }
}
