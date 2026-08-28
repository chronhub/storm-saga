<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimerStore;
use Storm\Saga\Testing\InMemory\ControlledClock;
use Storm\Saga\Testing\InMemory\InMemorySagaState;
use Storm\Saga\Testing\InMemory\InMemoryStepUnitOfWork;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowCommands;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowInstances;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowTimers;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * The shared store laws against the in-memory adapters, plus the two laws only this model owns:
 * the held key refuses the same id without blocking, and a nested unit of a different id is
 * refused loud rather than mis-scoped by a single snapshot.
 */
final class InMemorySagaStoreContractTest extends TestCase
{
    use SagaStoreContractLaws;

    private InMemorySagaState $state;

    private ControlledClock $clock;

    protected function setUp(): void
    {
        $this->state = new InMemorySagaState;
        $this->clock = new ControlledClock(PointInTime::from('2026-01-01T00:00:00.000000+00:00'));
    }

    #[Test]
    public function a_re_frozen_type_keeps_the_first_reason_and_the_first_instant(): void
    {
        // the payload half of the shared lift law, judged here because the port hands back only a
        // boolean: the DBAL twin buys this with an idempotent INSERT, this one with `??=`, and an
        // overwrite would re-date the freeze an operator reads as the moment it began
        $instances = $this->contractInstances();

        $instances->pauseType('law-frozen', 'window');
        $this->clock->advanceSeconds(60);
        $instances->pauseType('law-frozen', 'a different reason');

        $this->assertSame(
            ['reason' => 'window', 'pausedAt' => '2026-01-01T00:00:00.000000+00:00'],
            $this->state->typePauses['law-frozen'],
        );
    }

    #[Test]
    public function an_occupied_fence_refuses_the_same_id_without_blocking(): void
    {
        $fence = $this->contractFence();
        $id = new WorkflowId('law', 'c-held');

        $inner = null;
        $fence->tryWithin($id, function () use ($fence, $id, &$inner): void {
            $inner = $fence->tryWithin($id, static function (): void {});
        });

        $this->assertFalse($inner);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_nested_unit_of_another_id_is_refused_loud(): void
    {
        $fence = $this->contractFence();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('nested');

        $fence->tryWithin(new WorkflowId('law', 'c-outer'), function () use ($fence): void {
            $fence->tryWithin(new WorkflowId('law', 'c-inner'), static function (): void {});
        });
    }

    #[Test]
    public function the_claim_and_the_listing_order_deterministically_by_instant_then_arming(): void
    {
        // the model's own extra over the shared law: a tie on the fire instant settles by arming
        // order, so a scenario reads the same run after run
        $timers = $this->contractTimers();
        $now = $this->contractNow();
        $this->contractInstances()->create($this->row('law', 'c-tie'));
        $id = new WorkflowId('law', 'c-tie');

        $timers->arm($id, 'second', TimerKind::Timeout, $now);
        $timers->arm($id, 'third', TimerKind::Kick, $now);
        $timers->arm($id, 'first', TimerKind::Schedule, $now->subSeconds(1));

        $this->assertSame(['first', 'second', 'third'], array_map(static fn ($t): string => $t->stateKey, $timers->listFor($id)));
        $this->assertSame(['first', 'second', 'third'], array_map(static fn ($t): string => $t->stateKey, $timers->claimDue(10, $now)));
        $this->assertSame([3, 1, 2], array_map(static fn ($t): int => $t->id, $timers->listFor($id))); // minted 1,2,3 by arming, listed by instant
    }

    #[Test]
    public function a_touched_row_leaves_the_quiet_window(): void
    {
        // updatedAt is the quiet clock: a waived saga still being written is not handed to the sweep
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-quiet'));
        $waived = $instances->find(new WorkflowId('law', 'c-quiet'));
        $this->assertNotNull($waived);
        $instances->update(self::waivedCopy($waived, $this->clock->now()));

        $this->clock->advanceSeconds(120);
        $this->assertCount(1, $instances->waivedAndQuiet(60));

        $fresh = $instances->find(new WorkflowId('law', 'c-quiet'));
        $this->assertNotNull($fresh);
        $instances->update($fresh->restingAt($fresh->stateKey, $fresh->status, ['touched' => true], [], []));
        $this->assertSame([], $instances->waivedAndQuiet(60)); // the touch reset the quiet clock
    }

    #[Test]
    public function the_quiet_report_takes_only_running_waived_rows_oldest_waive_first(): void
    {
        $instances = $this->contractInstances();
        $now = $this->clock->now();

        foreach (['c-w-late', 'c-w-early', 'c-w-plain', 'c-w-done'] as $corr) {
            $instances->create($this->row('law', $corr));
        }
        $late = $instances->find(new WorkflowId('law', 'c-w-late'));
        $early = $instances->find(new WorkflowId('law', 'c-w-early'));
        $done = $instances->find(new WorkflowId('law', 'c-w-done'));
        $this->assertNotNull($late);
        $this->assertNotNull($early);
        $this->assertNotNull($done);
        $instances->update($late->waived($now->addSeconds(5)));
        $instances->update($early->waived($now));
        $instances->update($done->waived($now)->restingAt('done', WorkflowStatus::Completed, [], [], []));

        $this->clock->advanceSeconds(120);

        // running and waived only, the oldest waive first; the untouched plain row never reports
        $this->assertSame(
            ['c-w-early', 'c-w-late'],
            array_map(static fn (WorkflowInstanceRow $r): string => $r->correlationId, $instances->waivedAndQuiet(60)),
        );
        $this->assertSame(
            ['c-w-early'],
            array_map(static fn (WorkflowInstanceRow $r): string => $r->correlationId, $instances->waivedAndQuiet(60, 1)),
        );
    }

    #[Test]
    public function the_quiet_window_is_strict_to_the_second(): void
    {
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-w-edge'));
        $edge = $instances->find(new WorkflowId('law', 'c-w-edge'));
        $this->assertNotNull($edge);
        $instances->update($edge->waived($this->clock->now()));

        $this->clock->advanceSeconds(60);
        $this->assertSame([], $instances->waivedAndQuiet(60)); // exactly at the window: still active

        $this->clock->advanceSeconds(1);
        $this->assertCount(1, $instances->waivedAndQuiet(60));
    }

    #[Test]
    public function the_quiet_report_pages_at_one_hundred_rows_by_default(): void
    {
        $instances = $this->contractInstances();
        $now = $this->clock->now();
        for ($i = 1; $i <= 101; $i++) {
            $corr = sprintf('c-w-page-%03d', $i);
            $instances->create($this->row('law', $corr));
            $row = $instances->find(new WorkflowId('law', $corr));
            $this->assertNotNull($row);
            $instances->update($row->waived($now));
        }

        $this->clock->advanceSeconds(120);
        $this->assertCount(100, $instances->waivedAndQuiet(60));
    }

    #[Test]
    #[Group('adversarial')]
    public function the_state_cap_admits_a_row_landing_exactly_on_the_limit(): void
    {
        // the cap refuses only what EXCEEDS it: four bags, three empty and one padded so the
        // encoded sum lands exactly on the 8192-byte limit, must be admitted and read back
        $instances = $this->contractInstances();
        $pad = 8192 - 3 * strlen('[]') - strlen((string) json_encode(['p' => '']));
        $vars = ['p' => str_repeat('x', $pad)];
        $instances->create(WorkflowInstanceRow::fresh(new WorkflowId('law', 'c-cap-edge'), 'await', $vars, [], $this->contractNow(), 1));

        $this->assertSame($vars, $instances->find(new WorkflowId('law', 'c-cap-edge'))?->vars);
    }

    #[Test]
    public function the_relay_mark_takes_one_pending_row_once(): void
    {
        $commands = $this->contractCommands();
        assert($commands instanceof InMemoryWorkflowCommands);
        $this->contractInstances()->create($this->row('law', 'c-once'));
        $commands->write(new WorkflowId('law', 'c-once'), $this->sealed('m-once'), 'issuing', 3, 1);

        $this->assertFalse($commands->markPublished('m-ghost'));
        $this->assertTrue($commands->markPublished('m-once'));
        $this->assertFalse($commands->markPublished('m-once')); // no longer pending, no second mark
    }

    private static function waivedCopy(WorkflowInstanceRow $row, PointInTime $at): WorkflowInstanceRow
    {
        return $row->waived($at);
    }

    protected function contractInstances(): WorkflowInstanceStore
    {
        return new InMemoryWorkflowInstances($this->state, $this->clock);
    }

    protected function contractTimers(): WorkflowTimerStore
    {
        return new InMemoryWorkflowTimers($this->state, $this->clock);
    }

    protected function contractCommands(): WorkflowOutboxWriter
    {
        return new InMemoryWorkflowCommands($this->state, new DefaultMessageSerializer, $this->clock);
    }

    protected function contractFence(): SagaStepUnitOfWork
    {
        return new InMemoryStepUnitOfWork($this->state);
    }

    #[Test]
    public function the_one_second_window_holds_its_exact_boundary_without_a_floor(): void
    {
        // the boundary only this controlled clock can pin exactly: one second of quiet sits ON the
        // window-one cutoff, kept active by the strict compare, and one more second crosses it; the
        // shared zero-window law proves the sweep below, so no floor can hide between the two
        $instances = $this->contractInstances();
        $instances->create($this->row('law', 'c-w-one'));
        $row = $instances->find(new WorkflowId('law', 'c-w-one'));
        $this->assertNotNull($row);
        $instances->update($row->waived($this->clock->now()));

        $this->clock->advanceSeconds(1);
        $this->assertSame([], $instances->waivedAndQuiet(1));

        $this->clock->advanceSeconds(1);
        $this->assertCount(1, $instances->waivedAndQuiet(1));
    }

    protected function ageInstance(WorkflowId $id, int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }

    protected function contractNow(): PointInTime
    {
        return $this->clock->now();
    }

    protected function publish(WorkflowOutboxWriter $commands, string $correlationId, string $messageId): void
    {
        assert($commands instanceof InMemoryWorkflowCommands);
        $this->assertTrue($commands->markPublished($messageId));
    }
}
