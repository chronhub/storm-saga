<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Locking\SagaStepUnitOfWork;

/**
 * The whole saga command-outbox storage port, the two command capabilities composed:
 * `WorkflowCommandStore`, the step's seal-and-recall half, and `FailedWorkflowCommands`, the
 * dead-letter half. An adapter implements the union; a consumer asks for the half it uses.
 *
 * Storage only: the message arrives sealed, `WorkflowOutbox` having applied the `HopProtocol` with a
 * stable id, the saga's correlation, and the ambient principal, so an adapter stores it and never edits
 * it. The wire shape is not this port's concern, so it cannot diverge per storage technology. Member of
 * the saga's co-transactional group of instances, timers, and outbox: an adapter's writes MUST enlist
 * in the step's single unit of work per `SagaStepUnitOfWork` law 1, so the group swaps together, to a transactional
 * store, or not at all.
 *
 * @see SagaStepUnitOfWork
 * @see WorkflowOutbox
 * @see HopProtocol
 */
interface WorkflowOutboxWriter extends FailedWorkflowCommands, WorkflowCommandStore {}
