<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Locking\SagaStepUnitOfWork;

/**
 * The whole due-time scheduling store for `workflow_timers`, the two timer capabilities composed:
 * `WorkflowTimers`, the step's arm-read-cancel half, and `DueTimerQueue`, the runner's
 * claim-lease-park half. An adapter implements the union; a consumer asks for the half it uses.
 *
 * Times are `PointInTime`, Storm's canonical instant: UTC, microsecond, single string format. The store
 * serializes the framework's time, never an arbitrary `DateTimeImmutable`.
 *
 * Member of the saga co-transactional group of instances, timers, and outbox: an adapter's writes MUST
 * enlist in the step's single unit of work per `SagaStepUnitOfWork` law 1, so the group swaps together or not at
 * all.
 *
 * Lives in the package rather than Contracts because it names concrete row DTOs.
 *
 * @see \Storm\Clock\PointInTime
 * @see SagaStepUnitOfWork
 */
interface WorkflowTimerStore extends DueTimerQueue, WorkflowTimers {}
