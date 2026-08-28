<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Store\WorkflowInstanceRow;

/**
 * An advanced instance: persist the row under OCC via `WHERE version = loaded version`, apply the
 * ordered TimerOps and outbox commands, announce after commit.
 */
final readonly class Updated implements StepOutcome
{
    /**
     * @param  list<TimerOp>  $timerOps
     * @param  list<IssuedCommand>  $commands
     * @param  list<SagaAnnouncement>  $announcements
     * @param  object|null  $signalReply  the handler's typed reply, set only by a user-signal step;
     *                                    `executeSignalFor()` hands it out once the step has written
     */
    public function __construct(
        public WorkflowInstanceRow $row,
        public array $timerOps = [],
        public array $commands = [],
        public array $announcements = [],
        public ?object $signalReply = null,
    ) {}
}
