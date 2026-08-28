<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\RaceArm;

/**
 * One assembled arm of a race: the name that becomes the outbox `effect_group` and the log's arm
 * identity, the command class the stamping matches on, the outcome event class whose arrival makes
 * this arm the winner, and the resolved activity that undoes the arm's effect, run immediately when
 * the arm loses with its command already in flight, or at a later rollback when it won and the saga
 * unwinds.
 *
 * @see RacePolicy
 * @see RaceArm
 */
final readonly class RaceArmSlot
{
    /**
     * @param  class-string  $command
     * @param  class-string  $wonBy
     */
    public function __construct(
        public string $name,
        public string $command,
        public string $wonBy,
        public Activity $compensation,
    ) {}
}
