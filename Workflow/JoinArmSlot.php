<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\JoinArm;

/**
 * One assembled arm of a join: the name that becomes the outbox `effect_group` and the log's arm
 * identity, the command class the stamping matches on, the outcome event class whose arrival
 * completes this arm, the optional event class reporting its definitive failure, and the resolved
 * activity that undoes the arm's effect, run immediately when a sibling arm fails with this one
 * already completed, or at a later rollback when the join succeeded and the saga unwinds.
 *
 * @see JoinPolicy
 * @see JoinArm
 */
final readonly class JoinArmSlot
{
    /**
     * @param  class-string  $command
     * @param  class-string  $completedBy
     * @param  class-string|null  $failedBy
     */
    public function __construct(
        public string $name,
        public string $command,
        public string $completedBy,
        public ?string $failedBy,
        public Activity $compensation,
    ) {}
}
