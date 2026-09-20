<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;

/**
 * The step's writes as one unit: the resting row under optimistic concurrency, the folded timer
 * effects and the issued commands. An adapter that can write them in a single server statement does;
 * one that cannot writes them in this order, row, cancels, arms, commands, inside the step's unit of
 * work, so a refused row leaves no effect behind either way.
 *
 * @see SagaStorageFailure
 */
interface WorkflowStepWrites
{
    /**
     * Persist an advanced instance and its effects; nothing is written when the row's version moved.
     *
     * @param  list<OutboxEntry>  $commands
     *
     * @throws StaleWorkflowInstance when a competing step moved the OCC version underneath
     */
    public function commitAdvance(WorkflowInstanceRow $row, StepEffects $effects, array $commands): void;

    /**
     * Persist effects and commands for a row written by another means: a birth, or an escalation
     * that moves timers alone.
     *
     * @param  list<OutboxEntry>  $commands
     */
    public function applyEffects(WorkflowId $id, StepEffects $effects, array $commands): void;
}
