<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Event\SagaAnnouncement;

/**
 * One fenced step's observable facts, carried OUT of the unit of work for the executor's
 * post-settle half: the report the facade maps, the answering signal's reply when the step
 * captured one, the committed announcements a settled step dispatches, and the voiced no-ops, the
 * refused cancel and the discarded outcome, which wrote nothing and dispatch without a commit to
 * wait for. The dispatch rules stay the executor's; this value only says what happened inside.
 */
final readonly class StepResult
{
    /**
     * @param  list<SagaAnnouncement>  $announcements  dispatched only when the step APPLIED
     * @param  list<object>  $voiced  the no-op announcements, dispatched whatever the report
     */
    public function __construct(
        public ExecutionReport $report,
        public ?object $reply = null,
        public array $announcements = [],
        public array $voiced = [],
    ) {}

    public static function busy(): self
    {
        return new self(ExecutionReport::FenceBusy);
    }

    public static function nothing(): self
    {
        return new self(ExecutionReport::NothingToDo);
    }
}
