<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * The saga engine port, whole: the five role contracts composed into one facade. Start an
 * instance, deliver what just happened, and cancel; what is delivered is an event, a fired timer,
 * the global deadline, or a dead-lettered effect. A consumer asks for the ROLE it uses, so a
 * spawner cannot reach a timer callback and a delivery handler cannot cancel:
 *
 * - `SagaStarter`: birth, idempotent, under the guarded correlation namespace
 * - `SagaSignaller`: the event/signal split's signal half, answering included
 * - `SagaOutcomeDelivery`: events to waiting sagas, the transport-safe seam included
 * - `SagaTimerTarget`: the timer runner's callbacks, one verb per kind
 * - `SagaOperator`: cancel, the dead-lettered effect's settle, the state migration sweep
 * - `SagaFamilyTarget`: the poke a settling family member drives on its parent
 *
 * The full facade remains for a consumer that genuinely spans the surface, an application service
 * or a client facade. Abstracted so an application can substitute or decorate the engine, which a
 * final concrete facade carrying no contract cannot support. Module-local like every other Saga
 * port: the signatures need `ExecutionReport`, a Saga concrete, so it cannot live in Contracts.
 */
interface SagaEngine extends SagaFamilyTarget, SagaOperator, SagaOutcomeDelivery, SagaSignaller, SagaStarter, SagaTimerTarget {}
