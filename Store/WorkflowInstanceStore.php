<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Locking\SagaStepUnitOfWork;

/**
 * The whole saga instance store, the four capabilities composed: `WorkflowInstances`, the
 * repository core, `WorkflowFamilies`, the child topology and adoption reads, `WorkflowPauses`,
 * the operator freeze, and `SagaMaintenanceReader`, the cleanup's cold reads. An adapter
 * implements the union; a consumer asks for the capability it uses, and only the step executor,
 * which spans three of the four inside one unit of work, holds the union today.
 *
 * Member of the saga's co-transactional group of instances, timers, and outbox: an adapter's writes
 * MUST enlist in the step's single unit of work per `SagaStepUnitOfWork` law 1, so the group swaps together, to a
 * transactional store, or not at all.
 *
 * Lives in the package rather than Contracts because it names concrete row DTOs.
 *
 * @see SagaStepUnitOfWork
 */
interface WorkflowInstanceStore extends SagaMaintenanceReader, WorkflowFamilies, WorkflowInstances, WorkflowPauses {}
