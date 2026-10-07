<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Clock\FrozenClock;
use Storm\Message\ContextValues;
use Storm\Message\Message;
use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\OutboxEntry;
use Storm\Saga\Store\SequentialWorkflowStepWrites;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Store\WorkflowTimers;

/**
 * The composed writes a step lands through the three ports, in the order the atomic writer lands
 * them: the row, then the cancels, the arms, and the outbox commands.
 */
final class SequentialWorkflowStepWritesTest extends TestCase
{
    #[Test]
    public function an_advance_updates_the_row_then_applies_every_timer_effect_and_command(): void
    {
        $log = [];

        $instances = $this->createMock(WorkflowInstances::class);
        $instances->expects($this->once())->method('update')
            ->willReturnCallback(static function (WorkflowInstanceRow $row) use (&$log): void {
                $log[] = 'update '.$row->stateKey;
            });

        $timers = $this->createStub(WorkflowTimers::class);
        $timers->method('cancel')->willReturnCallback(static function (WorkflowId $id, string $stateKey) use (&$log): void {
            $log[] = 'cancel '.$id->correlationId.' '.$stateKey;
        });
        $timers->method('arm')->willReturnCallback(static function (WorkflowId $id, string $stateKey, TimerKind $kind) use (&$log): void {
            $log[] = 'arm '.$id->correlationId.' '.$stateKey.' '.$kind->value;
        });

        $store = $this->createStub(WorkflowCommandStore::class);
        $store->method('write')->willReturnCallback(static function (WorkflowId $id, Message $message, string $issuedFromState) use (&$log): void {
            $log[] = 'write '.$id->correlationId.' '.$issuedFromState;
        });

        $at = FrozenClock::at('2026-09-25T00:00:00.000000+00:00')->now();
        $effects = StepEffects::fold([
            ['op' => TimerOp::cancelState('charge'), 'fireAt' => $at],
            ['op' => TimerOp::armTimeout('await', 300), 'fireAt' => $at],
        ]);
        $entry = new OutboxEntry(new Message(new stdClass), 'charge', 3, 1, CommandPurpose::Forward);

        new SequentialWorkflowStepWrites($instances, $timers, new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $store))
            ->commitAdvance(new WorkflowInstanceRow('payment', 'p-1', 'await', WorkflowStatus::Running), $effects, [$entry]);

        $this->assertSame(['update await', 'cancel p-1 charge', 'arm p-1 await timeout', 'write p-1 charge'], $log);
    }
}
