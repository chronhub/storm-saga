<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore;

use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaOutcomeNotYetApplicable;
use Storm\Saga\Semaphore\Command\GrantSlot;
use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;
use Storm\Saga\Semaphore\Event\SemaphoreSlotRefused;
use Storm\Saga\Semaphore\Exception\InvalidSemaphoreProvision;
use Storm\Saga\Semaphore\Exception\SemaphoreUnavailable;
use Storm\Saga\Semaphore\Reply\Granted;
use Storm\Saga\Semaphore\Reply\Lost;
use Storm\Saga\Semaphore\Reply\Queued;
use Storm\Saga\Semaphore\Reply\Rejected;
use Storm\Saga\Semaphore\Reply\Renewed;
use Storm\Saga\Semaphore\Signal\Acquire;
use Storm\Saga\Semaphore\Signal\Release;
use Storm\Saga\Semaphore\Signal\Renew;
use Storm\Saga\Semaphore\Signal\Withdraw;
use Throwable;

/**
 * The consumer's facade over the shipped semaphore: provision a resource once, then acquire, release,
 * renew, and withdraw from your handlers. Every method resolves the semaphore instance by the RESOURCE
 * string, since the correlation is the resource.
 *
 * The intended call site for `acquireFor` is the handler of a command the WAITER's own activity issued:
 * by the time the relay dispatches that command, the waiter's step has committed and the saga rests on
 * its wait state, so the immediate `SemaphoreSlotGranted` or `SemaphoreSlotRefused` this client delivers
 * lands on a resting wait. A `Queued` answer delivers nothing; the grant will arrive from the
 * semaphore's own promotion, through the {@see GrantSlot} wake-up.
 *
 * Under concurrent pressure the semaphore's fence refuses rather than waits: `SagaFenceBusy` bubbles
 * on purpose, the transport redelivers the calling handler's message, and the retried acquire is
 * idempotent by token. That refusal-over-waiting is also the throughput warning made concrete, one
 * row serializes every verb of one resource.
 */
final readonly class SemaphoreClient
{
    /** The shipped workflow type every semaphore instance runs under. */
    public const string WORKFLOW_TYPE = 'storm_semaphore';

    public function __construct(
        private SagaEngine $engine,
    ) {}

    /**
     * Births the resource's semaphore with its cap and defaults; returns false when one already runs,
     * a benign re-provision. The queue TTL defaults to the grant TTL; the sweep interval defaults to
     * half the grant TTL, the backstop cadence for a semaphore nobody touches, since every verb
     * already reaps lazily.
     *
     * @throws InvalidSemaphoreProvision when a bound or TTL cannot guard anything
     * @throws Throwable propagated from the engine's start, which declares its own tail; the named
     *                   clauses above are the ones a caller acts on, never the whole set
     */
    public function provision(
        string $resource,
        int $capacity,
        int $grantTtlSeconds,
        int $maxQueue,
        ?int $queueTtlSeconds = null,
        ?int $sweepIntervalSeconds = null,
    ): bool {
        if ($capacity < 1) {
            throw InvalidSemaphoreProvision::capacity($capacity);
        }
        if ($maxQueue < 0) {
            throw InvalidSemaphoreProvision::maxQueue($maxQueue);
        }
        if ($grantTtlSeconds < 1) {
            throw InvalidSemaphoreProvision::ttl('grant TTL', $grantTtlSeconds);
        }

        $queueTtl = $queueTtlSeconds ?? $grantTtlSeconds;
        if ($queueTtl < 1) {
            throw InvalidSemaphoreProvision::ttl('queue TTL', $queueTtl);
        }

        $sweep = $sweepIntervalSeconds ?? max(1, intdiv($grantTtlSeconds, 2));
        if ($sweep < 1) {
            throw InvalidSemaphoreProvision::ttl('sweep interval', $sweep);
        }

        return $this->engine->start(
            self::WORKFLOW_TYPE,
            $resource,
            SemaphoreLedger::provisionVars($resource, $capacity, $grantTtlSeconds, $maxQueue, $queueTtl, $sweep),
        );
    }

    /**
     * Ask a slot for the waiter and tell it what happened: a `Granted` or `Rejected` answer is also
     * DELIVERED to the waiter as its wait-state event, so the waiting saga advances the same way
     * whether the slot came now or from a later promotion; a `Queued` answer delivers nothing yet.
     * Idempotent by token: redelivering the calling message re-asks and gets the same answer.
     *
     * @throws SemaphoreUnavailable when no provisioned semaphore answers for the resource
     * @throws SagaFenceBusy when a concurrent verb holds the resource's fence; the transport redelivers
     * @throws SagaOutcomeNotYetApplicable when the answer raced the waiter's own resting step; the transport redelivers and the re-asked acquire is idempotent
     * @throws Throwable propagated from the engine's signal and outcome routing, which declare their
     *                   own tail; the named clauses above are the ones a caller acts on
     */
    public function acquireFor(string $resource, string $waiterType, string $waiterCorrelation, ?int $grantTtlSeconds = null): Granted|Queued|Rejected
    {
        $reply = $this->engine->signalFor(
            self::WORKFLOW_TYPE,
            $resource,
            new Acquire($waiterType, $waiterCorrelation, $grantTtlSeconds),
        );

        if (! $reply instanceof Granted && ! $reply instanceof Queued && ! $reply instanceof Rejected) {
            throw SemaphoreUnavailable::for($resource);
        }

        // routeOutcome, not deliver: an answer racing the waiter's own resting step must redeliver,
        // never be silently dropped against a wait that isn't armed yet. SagaOutcomeNotYetApplicable
        // is retryable, and the re-asked acquire is idempotent by token
        if ($reply instanceof Granted) {
            $this->engine->routeOutcome($waiterCorrelation, new SemaphoreSlotGranted($resource, $reply->expiresAt));
        }
        if ($reply instanceof Rejected) {
            $this->engine->routeOutcome($waiterCorrelation, new SemaphoreSlotRefused($resource));
        }

        return $reply;
    }

    /**
     * The holder gives its slot back; true when the semaphore took the verb. Owed on every exit path,
     * success and compensation alike; the TTL only backstops the holder that crashed before this.
     */
    public function release(string $resource, string $waiterType, string $waiterCorrelation): bool
    {
        return $this->engine->signal(self::WORKFLOW_TYPE, $resource, new Release($waiterType, $waiterCorrelation));
    }

    /**
     * Re-stamp the holder's lease from now. `Lost` means the sweep already expropriated it: the
     * caller no longer owns the resource and must stop the work or acquire again.
     *
     * @throws SemaphoreUnavailable when no provisioned semaphore answers for the resource
     * @throws SagaFenceBusy when a concurrent verb holds the resource's fence; the transport redelivers
     * @throws Throwable propagated from the engine's signal, which declares its own tail; the named
     *                   clauses above are the ones a caller acts on
     */
    public function renew(string $resource, string $waiterType, string $waiterCorrelation, ?int $grantTtlSeconds = null): Renewed|Lost
    {
        $reply = $this->engine->signalFor(
            self::WORKFLOW_TYPE,
            $resource,
            new Renew($waiterType, $waiterCorrelation, $grantTtlSeconds),
        );

        if (! $reply instanceof Renewed && ! $reply instanceof Lost) {
            throw SemaphoreUnavailable::for($resource);
        }

        return $reply;
    }

    /**
     * The waiter abandons its queue place, the duty of its own patience deadline; also clears a grant
     * that raced the abandonment in flight. True when the semaphore took the verb.
     */
    public function withdraw(string $resource, string $waiterType, string $waiterCorrelation): bool
    {
        return $this->engine->signal(self::WORKFLOW_TYPE, $resource, new Withdraw($waiterType, $waiterCorrelation));
    }
}
