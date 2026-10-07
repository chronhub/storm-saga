<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Message\ContextValues;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Saga\Engine\RecallJudge;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Testing\InMemory\InMemorySagaState;
use Storm\Saga\Testing\InMemory\InMemoryWorkflowCommands;
use Storm\Saga\Tests\Testing\Fixture\ReserveInventory;
use Storm\Serializer\DefaultMessageSerializer;

/**
 * An in-memory outbox a unit test seeds with the rows a rollback path is judged on, and the recall
 * judge that reads it, so a path's use of the recall is proven without a database.
 */
final readonly class RecallOutbox
{
    public InMemoryWorkflowCommands $commands;

    public function __construct()
    {
        $this->commands = new InMemoryWorkflowCommands(new InMemorySagaState, new DefaultMessageSerializer, new MutableClock);
    }

    public function judge(): RecallJudge
    {
        return new RecallJudge(new WorkflowOutbox(new HopProtocol(ContextValues::empty()), $this->commands));
    }

    /**
     * Write one command of run `$generation` that `$fromState` issued, forward unless `$purpose` says
     * otherwise, `pending` and never claimed, under `$messageId` so a test can move it through the
     * relay's stand-ins.
     */
    public function issue(WorkflowId $id, string $fromState, string $messageId, int $generation = 1, ?string $effectGroup = null, CommandPurpose $purpose = CommandPurpose::Forward): void
    {
        $this->commands->write($id, new Message(new ReserveInventory($messageId), [Header::MessageId->value => $messageId]), $fromState, 1, $generation, $purpose, $effectGroup);
    }
}
