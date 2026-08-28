<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Engine\Outcome\Effects;
use Storm\Saga\Engine\Plan\HaltAtGlobalCap;
use Storm\Saga\Event\SagaAwaitEscalated;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * A per-wait deadline elapsed while an effect-gating wait is still in flight: re-arm the wait's own
 * timeout, a recurring liveness heartbeat, and announce SagaAwaitEscalated, never finalize. The
 * instance row is untouched, same state and same version: the wait resolves only when its outcome
 * event is delivered. Purely: the re-arm is a relative TimerOp the shell turns into a fire instant.
 *
 * Only the per-state timer escalates. The global cap, when it fires at a gating wait, either bounds
 * a non-retriable wait via HaltAtGlobalCap or is waived at a retriable one via WaiveGlobalCap, which
 * also disarms this heartbeat; it is never an EscalateWait, so this performer never sees it.
 *
 * At an indexed family's awaited wait the escalation is a WITNESS and not a repair, and it is no
 * longer the only one: a member's terminal settle pokes the parent, which is what actually spends a
 * conclusion the gate absorbed while that member still ran. What stays this announcement's alone is
 * the family that can never complete, a member whose spawn dead-lettered: no settle is coming, so
 * nothing pokes, and what the heartbeat names is what an operator must unpark.
 *
 * @see HaltAtGlobalCap
 * @see FamilyGate
 */
final readonly class WaitEscalator
{
    public function escalate(WorkflowDefinition $def, WorkflowInstanceRow $row): Effects
    {
        $announced = [new SagaAwaitEscalated($row->workflowType, $row->correlationId, $row->generation, $row->stateKey)];

        $state = $def->state($row->stateKey);
        if ($state instanceof WaitState && $state->timeout !== null) {
            return new Effects([TimerOp::armTimeout($row->stateKey, $state->timeout->seconds)], $announced);
        }

        // a gating wait without its own timeout because the definition drifted: keep watch without re-arming
        return new Effects([], $announced);
    }
}
