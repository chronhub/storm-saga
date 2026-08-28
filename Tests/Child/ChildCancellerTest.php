<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Child;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Clock\PointInTime;
use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCanceller;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Engine\ExecutionReport;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Event\SagaChildCancelSkipped;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Workflow\CorrelationReuse;

final class ChildCancellerTest extends TestCase
{
    /** @var list<object> */
    private array $announced = [];

    /** @var list<array{string, string, ?string, bool}> */
    private array $cancelled = [];

    #[Test]
    public function a_proven_child_is_cancelled_through_the_engine_force_inherited(): void
    {
        $child = $this->childRow('kyc_review', 'ident-1', 'kyc');
        $canceller = $this->canceller($child);

        $done = $canceller->cancel($this->command(force: true, reason: 'client left'));

        $this->assertTrue($done);
        $this->assertSame([['kyc_review', ChildCorrelation::mint('ident-1', 'kyc'), 'client left', true]], $this->cancelled);
        $this->assertSame([], $this->announced);
    }

    #[Test]
    public function a_missing_child_is_the_race_resolved_quiet_no_op(): void
    {
        $canceller = $this->canceller(null);

        $this->assertFalse($canceller->cancel($this->command()));
        $this->assertSame([], $this->cancelled);
        $this->assertSame([], $this->announced);
    }

    #[Test]
    public function a_row_that_is_not_a_child_is_never_cancelled(): void
    {
        $native = WorkflowInstanceRow::fresh(
            new WorkflowId('kyc_review', ChildCorrelation::mint('ident-1', 'kyc')),
            'await', [], [], PointInTime::from('2026-07-21T10:00:00.000000+00:00'), 1,
        );
        $canceller = $this->canceller($native);

        $this->assertFalse($canceller->cancel($this->command()));
        $this->assertSame([], $this->cancelled);
        $this->assertSkipped('not-a-child');
    }

    #[Test]
    public function another_parents_child_is_protected_by_the_proof(): void
    {
        $child = $this->childRow('kyc_review', 'OTHER-parent', 'kyc');
        $canceller = $this->canceller($child);

        $this->assertFalse($canceller->cancel($this->command()));
        $this->assertSame([], $this->cancelled);
        $this->assertSkipped('not-my-child');
    }

    #[Test]
    public function a_type_mismatch_is_announced_not_acted_on(): void
    {
        $child = $this->childRow('screening', 'ident-1', 'kyc');
        $canceller = $this->canceller($child);

        $this->assertFalse($canceller->cancel($this->command()));
        $this->assertSame([], $this->cancelled);
        $this->assertSkipped('type-mismatch');
    }

    private function command(bool $force = false, ?string $reason = null): CancelChildWorkflow
    {
        return CancelChildWorkflow::with('onboarding', 'ident-1', 'kyc_review', ChildCorrelation::mint('ident-1', 'kyc'), $reason, $force);
    }

    private function childRow(string $type, string $parentCorrelation, string $slot): WorkflowInstanceRow
    {
        $ref = new ParentRef('onboarding', $parentCorrelation, $parentCorrelation, $slot, 1);

        return WorkflowInstanceRow::fresh(
            new WorkflowId($type, ChildCorrelation::mint($parentCorrelation, $slot)),
            'await',
            [],
            [ParentRef::CONTEXT_KEY => $ref->toContext()],
            PointInTime::from('2026-07-21T10:00:00.000000+00:00'),
            1,
        );
    }

    private function assertSkipped(string $reason): void
    {
        $this->assertCount(1, $this->announced);
        $event = $this->announced[0];
        $this->assertInstanceOf(SagaChildCancelSkipped::class, $event);
        $this->assertSame($reason, $event->reason);
        // a skip never held the parent's claimed run, so the generation it announces is the honest
        // unknown; any other number would read as a real run the reader could correlate against
        $this->assertSame(0, $event->generation);
    }

    private function canceller(?WorkflowInstanceRow $child): ChildCanceller
    {
        $engine = new readonly class($this) implements SagaEngine
        {
            public function __construct(private ChildCancellerTest $test) {}

            public function cancel(string $workflowType, string $correlationId, ?string $reason = null, bool $force = false, ?string $causationId = null): bool
            {
                return $this->test->recordCancel($workflowType, $correlationId, $reason, $force);
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

            public function startOrThrow(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool
            {
                return false;
            }

            public function startOrSignal(string $workflowType, string $correlationId, object $signal, array $vars = [], array $context = [], ?string $causationId = null): bool
            {
                return false;
            }

            public function signalFor(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): ?object
            {
                return null;
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

        $store = new readonly class($child) implements WorkflowInstanceStore
        {
            public function __construct(private ?WorkflowInstanceRow $child) {}

            public function findByCorrelation(string $correlationId): ?WorkflowInstanceRow
            {
                return $this->child;
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

            public function countChildren(string $parentCorrelationId): int
            {
                return 0;
            }

            public function countChildrenSerialized(string $parentCorrelationId): int
            {
                return 0;
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
                return $this->findByCorrelation($correlationId);
            }
        };

        $dispatcher = new readonly class($this) implements EventDispatcherInterface
        {
            public function __construct(private ChildCancellerTest $test) {}

            public function dispatch(object $event): object
            {
                $this->test->recordAnnouncement($event);

                return $event;
            }
        };

        return new ChildCanceller($engine, $store, $dispatcher);
    }

    public function recordCancel(string $type, string $correlation, ?string $reason, bool $force): bool
    {
        $this->cancelled[] = [$type, $correlation, $reason, $force];

        return true;
    }

    public function recordAnnouncement(object $event): void
    {
        $this->announced[] = $event;
    }
}
