<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Child;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Clock\PointInTime;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\Engine\ExecutionReport;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Event\SagaChildSpawnSkipped;
use Storm\Saga\Exception\ChildSpawnRefused;
use Storm\Saga\Exception\ParentNotAdoptable;
use Storm\Saga\Exception\ParentNotBornYet;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CorrelationReuse;

final class ChildSpawnerTest extends TestCase
{
    /** @var list<object> */
    private array $announced = [];

    /** @var list<array{string, string, array<string, mixed>, array<string, mixed>}> */
    private array $started = [];

    private bool $startReturns = true;

    private ?ParentNotAdoptable $startThrows = null;

    private int $childrenCount = 0;

    #[Test]
    public function a_spawn_from_a_living_root_births_the_minted_child_with_its_lineage(): void
    {
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));

        $born = $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc', ['tier' => 'basic']));

        $this->assertTrue($born);
        $this->assertCount(1, $this->started);
        [$type, $correlation, $vars, $context] = $this->started[0];
        $this->assertSame('kyc_review', $type);
        $this->assertSame(ChildCorrelation::mint('ident-1', 'kyc'), $correlation);
        $this->assertSame(['tier' => 'basic'], $vars);
        $this->assertEquals(
            new ParentRef('onboarding', 'ident-1', 'ident-1', 'kyc', 1),
            ParentRef::fromContext($context),
        );
    }

    #[Test]
    public function a_spawn_from_a_child_extends_the_chain_root_kept_depth_incremented(): void
    {
        $parentCorrelation = ChildCorrelation::mint('ident-1', 'kyc');
        $asParent = new ParentRef('onboarding', 'ident-1', 'ident-1', 'kyc', 1);
        $row = $this->runningParent('kyc_review', $parentCorrelation, [ParentRef::CONTEXT_KEY => $asParent->toContext()]);

        $spawner = $this->spawner($row);
        $spawner->spawn(StartChildWorkflow::with('kyc_review', $parentCorrelation, 'screening', 'vendor', []));

        $ref = ParentRef::fromContext($this->started[0][3]);
        $this->assertNotNull($ref);
        $this->assertSame('ident-1', $ref->rootCorrelationId);
        $this->assertSame(2, $ref->depth);
    }

    #[Test]
    public function a_spawn_racing_its_parent_start_throws_retryable_instead_of_acking_the_child_away(): void
    {
        // the start-versus-spawn race: no row means the parent's inbox tx has not committed yet;
        // an announced skip here ACKS the spawn and loses the child forever;
        // the plain-retryable throw lets the transport redeliver until the start commits, and a
        // start that never commits dead-letters the spawn as evidence instead of silence
        $spawner = $this->spawner(null);

        try {
            $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));
            $this->fail('a missing parent row must throw ParentNotBornYet, never skip');
        } catch (ParentNotBornYet $e) {
            $this->assertStringContainsString('onboarding/ident-1', $e->getMessage());
            $this->assertStringContainsString('still in flight', $e->getMessage());
        }

        $this->assertSame([], $this->started);
        $this->assertSame([], $this->announced, 'the race is redelivered, never announced as a skip');
    }

    #[Test]
    public function a_terminal_parent_skips_the_cancel_versus_spawn_race_resolved(): void
    {
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1')->settled(WorkflowStatus::Compensated, [], []));

        $born = $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));

        $this->assertFalse($born);
        $this->assertSame([], $this->started);
        $this->assertSkipped('parent-terminal');
    }

    #[Test]
    public function a_parent_type_mismatch_skips_a_stale_command_never_adopts(): void
    {
        $spawner = $this->spawner($this->runningParent('transfer', 'ident-1'));

        $born = $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));

        $this->assertFalse($born);
        $this->assertSkipped('parent-mismatch');
    }

    #[Test]
    public function the_depth_ceiling_kills_a_spawn_cycle(): void
    {
        $correlation = 'root-1';
        $refContext = [];
        for ($depth = 1; $depth <= ChildCorrelation::MAX_DEPTH; $depth++) {
            $ref = new ParentRef($depth === 1 ? 'onboarding' : 'w', $correlation, 'root-1', 's'.$depth, $depth);
            $refContext = $ref->toContext();
            $correlation = $ref->childCorrelationId();
        }

        $row = $this->runningParent('w', $correlation, [ParentRef::CONTEXT_KEY => $refContext]);
        $spawner = $this->spawner($row);

        $this->expectException(ChildSpawnRefused::class);

        $spawner->spawn(StartChildWorkflow::with('w', $correlation, 'deeper', 'again'));
    }

    #[Test]
    public function the_depth_ceiling_itself_is_still_reachable(): void
    {
        $correlation = 'root-1';
        $refContext = [];
        for ($depth = 1; $depth < ChildCorrelation::MAX_DEPTH; $depth++) {
            $ref = new ParentRef($depth === 1 ? 'onboarding' : 'w', $correlation, 'root-1', 's'.$depth, $depth);
            $refContext = $ref->toContext();
            $correlation = $ref->childCorrelationId();
        }

        $row = $this->runningParent('w', $correlation, [ParentRef::CONTEXT_KEY => $refContext]);
        $spawner = $this->spawner($row);

        $born = $spawner->spawn(StartChildWorkflow::with('w', $correlation, 'leaf', 'last'));

        $this->assertTrue($born);
        $ref = ParentRef::fromContext($this->started[0][3]);
        $this->assertSame(ChildCorrelation::MAX_DEPTH, $ref?->depth);
    }

    #[Test]
    public function vars_at_exactly_the_ceiling_still_pass(): void
    {
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));
        $vars = ['b' => str_repeat('x', ChildSpawner::MAX_VARS_BYTES - 8)];
        $this->assertSame(ChildSpawner::MAX_VARS_BYTES, strlen(json_encode($vars, JSON_THROW_ON_ERROR)));

        $this->assertTrue($spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc', $vars)));
    }

    #[Test]
    public function the_children_ceiling_stops_a_spawning_loop(): void
    {
        $this->childrenCount = ChildSpawner::MAX_CHILDREN;
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));

        $this->expectException(ChildSpawnRefused::class);

        $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));
    }

    #[Test]
    public function the_vars_ceiling_refuses_a_payload_masquerading_as_state(): void
    {
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));

        $this->expectException(ChildSpawnRefused::class);

        $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc', ['blob' => str_repeat('x', ChildSpawner::MAX_VARS_BYTES + 1)]));
    }

    #[Test]
    public function a_redelivery_is_the_ports_quiet_no_op_not_a_skip(): void
    {
        $this->startReturns = false;
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));

        $born = $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));

        $this->assertFalse($born);
        $this->assertCount(1, $this->started);
        $this->assertSame([], $this->announced);
    }

    #[Test]
    public function a_parent_settling_between_the_pre_check_and_the_locked_proof_still_skips(): void
    {
        // The race the shared lock resolves: the pre-check saw a running parent, the birth's own
        // locked read saw it terminal. The spawn must take the ANNOUNCED-skip channel, never the
        // dead-letter one; losing this race is normal, not poison.
        $this->startThrows = ParentNotAdoptable::terminal(
            ChildCorrelation::mint('ident-1', 'kyc'),
            'ident-1',
            WorkflowStatus::Compensated,
        );
        $spawner = $this->spawner($this->runningParent('onboarding', 'ident-1'));

        $born = $spawner->spawn(StartChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', 'kyc'));

        $this->assertFalse($born);
        $this->assertSkipped('parent-terminal');
    }

    #[Test]
    public function the_locked_proof_speaks_the_same_skip_vocabulary_as_the_pre_check(): void
    {
        // The reason travels from the engine's refusal to the announcement, so a consumer cannot
        // tell which of the two guards fired; the channel is the contract, not the guard's location.
        $child = ChildCorrelation::mint('ident-1', 'kyc');

        $this->assertSame('parent-missing', ParentNotAdoptable::missing($child, 'ident-1')->reason);
        $this->assertSame('parent-mismatch', ParentNotAdoptable::typeMismatch($child, 'ident-1', 'onboarding', 'billing')->reason);
        $this->assertSame('parent-terminal', ParentNotAdoptable::terminal($child, 'ident-1', WorkflowStatus::Completed)->reason);
    }

    private function assertSkipped(string $reason): void
    {
        $this->assertCount(1, $this->announced);
        $event = $this->announced[0];
        $this->assertInstanceOf(SagaChildSpawnSkipped::class, $event);
        $this->assertSame($reason, $event->reason);
        // a skip never held the parent's claimed run, and may precede its existence, so the
        // generation announced is the honest unknown rather than a run a reader could correlate
        $this->assertSame(0, $event->generation);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function runningParent(string $type, string $correlation, array $context = []): WorkflowInstanceRow
    {
        return WorkflowInstanceRow::fresh(
            new WorkflowId($type, $correlation),
            'await',
            [],
            $context,
            PointInTime::from('2026-07-21T10:00:00.000000+00:00'),
            1,
        );
    }

    private function spawner(?WorkflowInstanceRow $parent): ChildSpawner
    {
        $engine = new readonly class($this) implements SagaEngine
        {
            public function __construct(private ChildSpawnerTest $test) {}

            public function startOrThrow(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool
            {
                return $this->test->recordStart($workflowType, $correlationId, $vars, $context);
            }

            public function startOrSignal(string $workflowType, string $correlationId, object $signal, array $vars = [], array $context = [], ?string $causationId = null): bool
            {
                return false;
            }

            public function signalFor(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): ?object
            {
                return null;
            }

            public function signal(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): bool
            {
                return false;
            }

            public function pokeFamily(string $workflowType, string $correlationId, ?string $causationId = null): bool
            {
                return false;
            }

            public function start(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool
            {
                return false;
            }

            public function deliver(string $workflowType, string $correlationId, object $event, ?string $causationId = null): bool
            {
                return false;
            }

            public function timeout(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool
            {
                return false;
            }

            public function kick(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool
            {
                return false;
            }

            public function schedule(string $workflowType, string $correlationId, string $expectedStateKey, PointInTime $dueAt, ?string $causationId = null): bool
            {
                return false;
            }

            public function globalTimeout(string $workflowType, string $correlationId, ?string $causationId = null): bool
            {
                return false;
            }

            public function cancel(string $workflowType, string $correlationId, ?string $reason = null, bool $force = false, ?string $causationId = null): bool
            {
                return false;
            }

            public function deliverByCorrelation(string $correlationId, object $event, ?string $causationId = null): bool
            {
                return false;
            }

            public function routeOutcome(string $correlationId, object $event, ?string $causationId = null): bool
            {
                return false;
            }

            public function failIssuedEffect(string $correlationId, ?string $causationId = null, ?string $failedMessageId = null): ExecutionReport
            {
                return ExecutionReport::NothingToDo;
            }

            public function migrateState(string $workflowType, string $correlationId): bool
            {
                return false;
            }
        };

        $store = new readonly class($this, $parent) implements WorkflowInstanceStore
        {
            public function __construct(private ChildSpawnerTest $test, private ?WorkflowInstanceRow $parent) {}

            public function findByCorrelation(string $correlationId): ?WorkflowInstanceRow
            {
                return $this->parent;
            }

            public function countChildren(string $parentCorrelationId): int
            {
                return $this->test->childrenCount();
            }

            public function countChildrenSerialized(string $parentCorrelationId): int
            {
                return $this->test->childrenCount();
            }

            public function pauseInstance(WorkflowId $id, ?string $reason): bool
            {
                return false;
            }

            public function resumeInstance(WorkflowId $id): bool
            {
                return false;
            }

            public function pauseType(string $workflowType, ?string $reason): void {}

            public function resumeType(string $workflowType): bool
            {
                return false;
            }

            public function pausedType(string $workflowType): bool
            {
                return false;
            }

            public function spawnedMembers(string $parentCorrelationId, string $family): int
            {
                return 0;
            }

            public function livingMembers(string $parentCorrelationId, string $family): int
            {
                return 0;
            }

            public function livingChildren(string $parentCorrelationId): array
            {
                return [];
            }

            public function loadAdoptableParent(string $correlationId): ?WorkflowInstanceRow
            {
                return $this->parent;
            }

            public function find(WorkflowId $id): ?WorkflowInstanceRow
            {
                return null;
            }

            public function create(WorkflowInstanceRow $row, CorrelationReuse $reuse = CorrelationReuse::Reject): int
            {
                return 1;
            }

            public function update(WorkflowInstanceRow $row): void {}

            public function delete(WorkflowId $id): void {}

            public function strandedByFailedEffect(int $limit = 1000): array
            {
                return [];
            }

            public function waivedAndQuiet(int $quietForSeconds, int $limit = 100): array
            {
                return [];
            }

            public function runningCountsByVersion(): array
            {
                return [];
            }
        };

        $dispatcher = new readonly class($this) implements EventDispatcherInterface
        {
            public function __construct(private ChildSpawnerTest $test) {}

            public function dispatch(object $event): object
            {
                $this->test->recordAnnouncement($event);

                return $event;
            }
        };

        return new ChildSpawner($engine, $store, $store, $dispatcher);
    }

    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, mixed>  $context
     */
    public function recordStart(string $type, string $correlation, array $vars, array $context): bool
    {
        $this->started[] = [$type, $correlation, $vars, $context];

        if ($this->startThrows !== null) {
            throw $this->startThrows;
        }

        return $this->startReturns;
    }

    public function recordAnnouncement(object $event): void
    {
        $this->announced[] = $event;
    }

    public function childrenCount(): int
    {
        return $this->childrenCount;
    }
}
