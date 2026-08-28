<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Console;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Console\SagaPauseCommand;
use Storm\Saga\Console\SagaResumeCommand;
use Storm\Saga\Event\SagaPaused;
use Storm\Saga\Event\SagaResumed;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator freeze and its lift, judged on what each verb DOES and on what a refusal must not do.
 *
 * Both verbs answer on two scopes, an instance and a whole type, and both can refuse. The refusal
 * carries the weight: it must leave the store and the bus untouched, since an operator who froze
 * nothing has been told nothing happened. The claims' release rides INSIDE the store's resume
 * verbs, one atomic unit judged in the integration twin, never a second call this command could
 * forget. The exit code carries the rest, `FAILURE` and not `INVALID`: the invocation was well
 * formed, so what refused is the world, and `INVALID` is reserved for an operator who mistyped.
 *
 * The two scopes are deliberately NOT symmetric, and the asymmetry is pinned here: freezing a type
 * always succeeds, since registering a pause that is already registered changes nothing an operator
 * needs to hear, while lifting one that was never frozen is a mistake worth naming. Only the
 * instance scope announces on the bus; a fleet-wide freeze has no single generation to announce for.
 *
 * @see \Storm\Tests\Integration\Saga\SagaPauseCommandTest the same verbs against a real store
 */
final class SagaPauseCommandTest extends TestCase
{
    #[Test]
    public function freezing_one_instance_stamps_it_and_announces_its_generation(): void
    {
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseInstance')->willReturn(true);
        $instances->method('find')->willReturn($this->row(generation: 7));

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $e): bool => $e instanceof SagaPaused && $e->generation === 7));

        $tester = new CommandTester(new SagaPauseCommand($instances, $instances, $events, self::registry('transfer')));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'c-1', '--reason' => 'incident 4213']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('is frozen', $tester->getDisplay());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_freeze_whose_row_vanished_between_the_stamp_and_the_read_announces_generation_zero(): void
    {
        // the stamp succeeded, so a freeze DID happen and must be announced; the read that follows is
        // a second trip and can find nothing, a settle having landed in between. Zero is the only
        // honest generation there, and announcing 1 would name a run that never froze.
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseInstance')->willReturn(true);
        $instances->method('find')->willReturn(null);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $e): bool => $e instanceof SagaPaused && $e->generation === 0));

        $tester = new CommandTester(new SagaPauseCommand($instances, $instances, $events, self::registry('transfer')));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'c-1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function a_refused_instance_freeze_announces_nothing_and_fails(): void
    {
        // absent, settled or already paused: nothing changed, so nothing may be announced. FAILURE and
        // not INVALID, since the invocation was fine and it is the target that was not freezable
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('pauseInstance')->willReturn(false);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->never())->method('dispatch');

        $tester = new CommandTester(new SagaPauseCommand($instances, $instances, $events, self::registry('transfer')));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'ghost']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing frozen', $tester->getDisplay());
    }

    #[Test]
    public function freezing_a_whole_type_carries_the_reason_and_announces_nothing(): void
    {
        // the fleet scope has no single generation to announce for, so it stamps and stays silent
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseType')->with('transfer', 'incident 4213');
        $instances->expects($this->never())->method('pauseInstance');

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->never())->method('dispatch');

        $tester = new CommandTester(new SagaPauseCommand($instances, $instances, $events, self::registry('transfer')));
        $tester->execute(['workflowType' => 'transfer', '--reason' => 'incident 4213']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        // a fragment short enough not to cross SymfonyStyle's wrap
        $this->assertStringContainsString('no next step runs', $tester->getDisplay());
    }

    #[Test]
    public function the_reason_is_optional_and_reaches_the_store_as_null(): void
    {
        // attributability is asked for, never enforced: an incident at 3am must not be blocked on a flag
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('pauseType')->with('transfer', null);

        $tester = new CommandTester(new SagaPauseCommand($instances, $instances, $this->createStub(EventDispatcherInterface::class), self::registry('transfer')));
        $tester->execute(['workflowType' => 'transfer']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function lifting_one_instance_announces_its_generation(): void
    {
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeInstance')->willReturn(true);
        $instances->method('find')->willReturn($this->row(generation: 3));

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $e): bool => $e instanceof SagaResumed && $e->generation === 3));

        $tester = new CommandTester(new SagaResumeCommand($instances, $instances, $events));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'c-1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        // the method's name promises an announcement, and an operator running this by hand has only
        // that line to learn the saga is executable again and what happens to its held work
        $this->assertStringContainsString('resumed: executable again', $tester->getDisplay());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lift_whose_row_vanished_between_the_stamp_and_the_read_announces_generation_zero(): void
    {
        // same second trip as the freeze above, same answer: the lift happened and is announced, with
        // the only generation a vanished row can honestly carry
        $instances = $this->createMock(WorkflowInstanceStore::class);
        $instances->expects($this->once())->method('resumeInstance')->willReturn(true);
        $instances->method('find')->willReturn(null);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $e): bool => $e instanceof SagaResumed && $e->generation === 0));

        $tester = new CommandTester(new SagaResumeCommand($instances, $instances, $events));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'c-1']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function a_refused_instance_lift_announces_nothing_and_fails(): void
    {
        // absent or not paused: the store's composite verb refused whole, so nothing may be announced
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeInstance')->willReturn(false);

        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects($this->never())->method('dispatch');

        $tester = new CommandTester(new SagaResumeCommand($instances, $instances, $events));
        $tester->execute(['workflowType' => 'transfer', 'correlationId' => 'ghost']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing lifted', $tester->getDisplay());
    }

    #[Test]
    public function lifting_a_type_that_was_never_frozen_fails(): void
    {
        // the asymmetry with the freeze: registering a pause twice tells an operator nothing, lifting a
        // freeze that never existed means the operator is looking at the wrong type
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeType')->willReturn(false);

        $tester = new CommandTester(new SagaResumeCommand($instances, $instances, $this->createStub(EventDispatcherInterface::class)));
        $tester->execute(['workflowType' => 'transfer']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('was not frozen', $tester->getDisplay());
    }

    #[Test]
    public function lifting_a_frozen_type_reports_the_fleet_consequences(): void
    {
        $instances = $this->createStub(WorkflowInstanceStore::class);
        $instances->method('resumeType')->willReturn(true);

        $tester = new CommandTester(new SagaResumeCommand($instances, $instances, $this->createStub(EventDispatcherInterface::class)));
        $tester->execute(['workflowType' => 'transfer']);

        // the fleet line names what the operator must go and check afterwards, the facts the window
        // dead-lettered; without it a type-wide resume reports nothing but an exit code
        $this->assertStringContainsString('resumed: steps run, births are accepted', $tester->getDisplay());

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * A registry holding exactly the named types, so a freeze can be judged against a real one.
     *
     * @param  non-empty-string  ...$types
     */
    private static function registry(string ...$types): WorkflowRegistry
    {
        return new WorkflowRegistry(array_map(
            static fn (string $name): WorkflowDefinition => new WorkflowDefinition($name, ['done' => new FinalState('done')], 'done'),
            $types,
        ));
    }

    private function row(int $generation): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('transfer', 'c-1', 'await', WorkflowStatus::Running, generation: $generation);
    }
}
