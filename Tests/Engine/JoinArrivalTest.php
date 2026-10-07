<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use ErrorException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Event\SagaJoinArmArrived;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\AlphaCommand;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\BetaCommand;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\WorkflowDefinition;

use function restore_error_handler;
use function set_error_handler;

/**
 * The join's gate before the machine, over a three-arm join: what a partial completion leaves in the
 * row, which completion is the last, and what the gate hands back untouched. Every call runs under an
 * error handler that turns a PHP warning into an exception, as the framework's debug handler does, so
 * a read the gate is not meant to make fails here instead of passing as noise.
 */
final class JoinArrivalTest extends TestCase
{
    private const string NOW = '2026-01-01T00:00:00.000000+00:00';

    #[Test]
    public function a_partial_completion_marks_its_arm_logs_it_confirmed_and_names_the_arms_still_out(): void
    {
        $alpha = CompensationRecord::forArm('quote', 'alpha', CompensationStatus::Pending, confirmed: true, at: self::NOW);
        $row = $this->restingAt('await', ['quote' => ['alpha']], [$alpha]);

        $result = $this->gate($row, new SettlementSettled);

        $this->assertInstanceOf(Rested::class, $result);
        $this->assertSame(['quote' => ['alpha', 'beta']], $result->row->arms);
        $this->assertEquals([
            $alpha,
            CompensationRecord::forArm('quote', 'beta', CompensationStatus::Pending, confirmed: true, at: FrozenClock::at(self::NOW)->now()->toString()),
        ], $result->row->compensations);
        $this->assertEquals([new SagaJoinArmArrived('join-arrival', 'j-1', 1, 'quote', 'beta', ['gamma'])], $result->announcements);
        $this->assertInstanceOf(SagaJoinArmArrived::class, $result->announcements[0]);
        $this->assertSame(['gamma'], $result->announcements[0]->remaining);
    }

    #[Test]
    public function the_completion_that_covers_every_arm_is_handed_to_the_machine(): void
    {
        // the arrivals already marked count with the one arriving: the join is complete, the machine
        // crosses, and the settle after it accounts for the last arm
        $this->assertNull($this->gate($this->restingAt('await', ['quote' => ['alpha', 'beta']]), new GammaDelivered));
    }

    #[Test]
    public function a_redelivered_completion_is_absorbed(): void
    {
        $this->assertInstanceOf(Unmoved::class, $this->gate($this->restingAt('await', ['quote' => ['alpha']]), new SampleEvent));
    }

    #[Test]
    public function a_wait_that_joins_nothing_is_never_judged_as_the_join(): void
    {
        // `review` awaits the class alpha completes with, yet only the joining wait gates arrivals:
        // anywhere else the event is the machine's to route
        $this->assertNull($this->gate($this->restingAt('review', ['quote' => ['beta']]), new SampleEvent));
    }

    /**
     * @param  array<string, list<string>>  $arms
     * @param  list<CompensationRecord>  $compensations
     */
    private function restingAt(string $stateKey, array $arms, array $compensations = []): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('join-arrival', 'j-1', $stateKey, WorkflowStatus::Running, compensations: $compensations, arms: $arms);
    }

    private function gate(WorkflowInstanceRow $row, object $event): Rested|Unmoved|null
    {
        $settler = new JoinSettler(
            new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->createStub(WorkflowCommandStore::class)),
            FrozenClock::at(self::NOW),
            new WaitVarExtractor($this->createStub(EventResolver::class)),
        );

        set_error_handler(static fn (int $severity, string $message, string $file, int $line): never => throw new ErrorException($message, 0, $severity, $file, $line));

        try {
            return $settler->gateArrival($this->definition(), $row, Stimulus::event($event));
        } finally {
            restore_error_handler();
        }
    }

    private function definition(): WorkflowDefinition
    {
        $workflow = new #[Workflow(name: 'join-arrival')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'review', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'quote', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'quote', arm: 'gamma', command: GammaCommand::class, completedBy: GammaDelivered::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, GammaDelivered::class], heartbeatSeconds: 60)]
        #[WaitFor(state: 'review', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'done')]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'review')]
        #[On(from: 'review', trigger: 'event', to: 'done')]
        class {};

        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]))->build($workflow);
    }
}

/**
 * The third arm's command and completion: the build rules only need them to exist and to stand in no
 * subtype relation with the other arms' classes.
 */
final readonly class GammaCommand {}

final class GammaDelivered {}
