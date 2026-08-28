<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Run;

use Storm\Saga\Engine\IssuedCommand;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Store\WorkflowInstanceRow;

/**
 * The saga moved and rested: the resting row to persist under OCC, the ordered TimerOps, the
 * commands to write to the saga outbox, and the `Saga*` announcements to dispatch after commit.
 */
final readonly class Rested implements MachineRun
{
    /**
     * @param  list<SagaAnnouncement>  $announcements
     * @param  list<TimerOp>  $timerOps  applied strictly in order, leave-then-re-enter: `cancel X … arm X`
     * @param  list<IssuedCommand>  $commands  each with its provenance, the outbox row's pairing input
     */
    public function __construct(
        public WorkflowInstanceRow $row,
        public array $announcements = [],
        public array $timerOps = [],
        public array $commands = [],
    ) {}
}
