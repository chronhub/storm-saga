<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Store\WorkflowInstanceRow;

/**
 * A fresh instance: insert the row, apply the ordered TimerOps and outbox commands, announce after
 * commit.
 */
final readonly class Created implements StepOutcome
{
    /**
     * @param  list<TimerOp>  $timerOps
     * @param  list<IssuedCommand>  $commands
     * @param  list<SagaAnnouncement>  $announcements
     */
    public function __construct(
        public WorkflowInstanceRow $row,
        public array $timerOps = [],
        public array $commands = [],
        public array $announcements = [],
    ) {}
}
