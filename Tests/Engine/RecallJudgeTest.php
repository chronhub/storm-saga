<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\ContextValues;
use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\RecallJudge;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\RecallOutbox;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;

/**
 * The rollback's recall judge: which logged entries it proves never dispatched, read from the rows
 * the outbox holds.
 */
final class RecallJudgeTest extends TestCase
{
    #[Test]
    public function an_entry_whose_only_row_no_relay_claimed_is_recalled(): void
    {
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'charge', 'm-charge');
        $entry = CompensationRecord::pending('charge');

        $this->assertTrue($outbox->judge()->judge($this->halted([$entry]))->recalls($entry));
    }

    #[Test]
    public function one_published_row_keeps_the_entry_whatever_its_unclaimed_siblings(): void
    {
        // the published do may have landed: an unclaimed sibling, stored or riding the step, proves
        // nothing about it
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'charge', 'm-sent');
        $outbox->issue($this->id(), 'charge', 'm-fresh');
        $outbox->commands->markPublished('m-sent');
        $entry = CompensationRecord::pending('charge');
        $run = $this->halted([$entry], [new IssuedCommand('charge', new stdClass, CommandPurpose::Forward)]);

        $this->assertFalse($outbox->judge()->judge($run)->recalls($entry));
    }

    #[Test]
    public function a_row_a_relay_tried_and_released_keeps_the_entry(): void
    {
        // still pending, yet the attempt may have landed
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'charge', 'm-tried');
        $outbox->commands->markAttempted('m-tried');
        $entry = CompensationRecord::pending('charge');

        $this->assertFalse($outbox->judge()->judge($this->halted([$entry]))->recalls($entry));
    }

    #[Test]
    public function an_entry_that_issued_no_forward_command_is_never_recalled(): void
    {
        // no proof by vacuity: another state's rows, this state's undo, another run's, another arm's and
        // another saga's rows, and a compensation riding the step are all someone else's evidence
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'ship', 'm-ship');
        $outbox->issue($this->id(), 'charge', 'm-undo', purpose: CommandPurpose::Compensation);
        $outbox->issue($this->id(), 'charge', 'm-run2', generation: 2);
        $outbox->issue($this->id(), 'charge', 'm-arm', effectGroup: 'left');
        $outbox->issue(new WorkflowId('payment', 'o-2'), 'charge', 'm-theirs');
        $entry = CompensationRecord::pending('charge');
        $run = $this->halted([$entry], [
            new IssuedCommand('charge', new stdClass, CommandPurpose::Compensation),
            new IssuedCommand('ship', new stdClass, CommandPurpose::Forward),
        ]);

        $this->assertFalse($outbox->judge()->judge($run)->recalls($entry));
    }

    #[Test]
    public function a_confirmed_entry_is_never_requalified(): void
    {
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'charge', 'm-charge');
        $entry = CompensationRecord::pending('charge')->confirm();

        $this->assertFalse($outbox->judge()->judge($this->halted([$entry]))->recalls($entry));
    }

    #[Test]
    public function each_entry_is_judged_on_its_own_rows(): void
    {
        $outbox = new RecallOutbox;
        $outbox->issue($this->id(), 'charge', 'm-charge');
        $outbox->issue($this->id(), 'ship', 'm-ship');
        $outbox->commands->markPublished('m-ship');
        $charge = CompensationRecord::pending('charge');
        $ship = CompensationRecord::pending('ship');

        $verdict = $outbox->judge()->judge($this->halted([$charge, $ship]));

        $this->assertTrue($verdict->recalls($charge));
        $this->assertFalse($verdict->recalls($ship));
    }

    #[Test]
    public function a_settled_entry_is_never_read(): void
    {
        $store = $this->createMock(WorkflowCommandStore::class);
        $store->expects($this->never())->method('forwardClaims');
        $settled = CompensationRecord::forArm('race', 'left', CompensationStatus::Skipped, false, 'recalled: never dispatched');

        $verdict = $this->judgeOver($store)->judge($this->halted([$settled]));

        $this->assertFalse($verdict->recalls($settled));
    }

    #[Test]
    public function a_run_that_did_not_halt_reads_nothing(): void
    {
        // a rollback is owed only by a halt: any other rest must neither read nor lock the outbox
        $store = $this->createMock(WorkflowCommandStore::class);
        $store->expects($this->never())->method('forwardClaims');
        $entry = CompensationRecord::pending('charge');
        $running = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, [], [], [], 0, null, [$entry]);

        $verdict = $this->judgeOver($store)->judge(new Rested($running, commands: [new IssuedCommand('charge', new stdClass, CommandPurpose::Forward)]));

        $this->assertFalse($verdict->recalls($entry));
    }

    private function id(): WorkflowId
    {
        return new WorkflowId('payment', 'o-1');
    }

    private function judgeOver(WorkflowCommandStore $store): RecallJudge
    {
        return new RecallJudge(new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $store));
    }

    /**
     * @param  list<CompensationRecord>  $log
     * @param  list<IssuedCommand>  $commands
     */
    private function halted(array $log, array $commands = []): Rested
    {
        return new Rested(new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Halted, [], [], [], 0, null, $log), commands: $commands);
    }
}
