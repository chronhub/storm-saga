<?php

declare(strict_types=1);

namespace Storm\Saga\Locking;

use Closure;
use RuntimeException;
use Storm\Saga\Store\WorkflowId;
use Throwable;

/**
 * Single-writer guard for a saga instance: a step takes the fence for `(workflowType, correlationId)`
 * so two workers never advance the same instance at once. It is a fast-path guard, not the only one;
 * the instance store's OCC `version` is the backstop if a step ever races past the fence.
 *
 * The fence carries a double contract: mutual exclusion AND the step's atomicity scope. It is three
 * adapter laws:
 *
 *  1. `$work` runs inside one atomic unit shared with the co-transactional group's connection for
 *     instances, timers, and the outbox writer: everything they write commits or rolls back together.
 *
 *  2. The exclusion holds exactly until that unit settles, never released before the commit, never
 *     held past it; the PG adapter gets this for free because `pg_try_advisory_xact_lock` is
 *     transaction-scoped by nature.
 *
 *  3. Try-skip: never block; an occupied fence returns `false` and the caller's durable timer re-tries.
 *
 * Consequence for "don't get locked in": a lock service alone such as a Redis lock is NOT a step unit of work,
 * since law 1 is unsatisfiable without the relational unit of work; an alternative RDBMS adapter is
 * possible with care, for example MySQL `GET_LOCK` released after commit keeps law 2 honest.
 */
interface SagaStepUnitOfWork
{
    /**
     * Run `$work` while holding the fence for `$id`. Returns `true` if the fence was acquired and
     * `$work` ran; `false` if another worker already holds it, when the caller then re-kicks without
     * blocking. A throwable from `$work` propagates and still releases the fence.
     *
     * @param  Closure():void  $work
     *
     * @throws Throwable propagated from `$work`; the fence is released either way
     * @throws RuntimeException on a failure acquiring the fence or its transaction
     */
    public function tryWithin(WorkflowId $id, Closure $work): bool;
}
