<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Closure;
use RuntimeException;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Store\WorkflowId;
use Throwable;

/**
 * The in-memory `SagaStepUnitOfWork`: a per-id reentrancy guard plus a snapshot of the WHOLE shared state,
 * honoring the port's three adapter laws in sequential form. Law 1 by snapshot and restore, since
 * every adapter writes the same `InMemorySagaState`, a throwable restores instances, correlations,
 * timers, and commands together; law 2 by construction, the key is released in the same finally
 * that settles the unit; law 3 by the held-key check, an occupied fence returns false without
 * blocking, which is how a test stages deterministic reentrant contention.
 *
 * What this deliberately does NOT model: advisory locks between processes, `SKIP LOCKED`, or the
 * savepoint nesting a DBAL fence performs under an ambient transaction. A nested step is refused
 * loud instead, since a single restore under silent nesting would roll back the outer step's
 * writes with the inner's.
 */
final readonly class InMemoryStepUnitOfWork implements SagaStepUnitOfWork
{
    public function __construct(
        private InMemorySagaState $state,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws RuntimeException when a step is already running; nesting would make the single
     *                          snapshot restore the wrong scope, so it is refused rather than
     *                          mis-modeled
     */
    public function tryWithin(WorkflowId $id, Closure $work): bool
    {
        $key = $this->state->instanceKey($id);
        if (isset($this->state->held[$key])) {
            return false;
        }

        if ($this->state->inStep) {
            throw new RuntimeException('The in-memory unit of work refuses a nested step: one snapshot cannot scope two.');
        }

        // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset above, so the value is arbitrary
        $this->state->held[$key] = true;
        $this->state->inStep = true;
        $snapshot = $this->state->snapshot();

        try {
            $work();
        } catch (Throwable $e) {
            $this->state->restore($snapshot);

            throw $e;
        } finally {
            unset($this->state->held[$key]);
            $this->state->inStep = false;
        }

        return true;
    }
}
