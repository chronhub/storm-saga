<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Saga\Attributes\ExposesState;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Signal;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Semaphore\Signal\Acquire;
use Storm\Saga\Semaphore\Signal\Release;
use Storm\Saga\Semaphore\Signal\Renew;
use Storm\Saga\Semaphore\Signal\Withdraw;
use Storm\Saga\Workflow\CorrelationReuse;
use Storm\Saga\Workflow\SignalResult;

/**
 * The shipped durable semaphore: at most N concurrent works against a shared resource, the saga-shaped
 * half of rate limiting. One instance per resource, the CORRELATION IS the resource, provisioned through
 * {@see SemaphoreClient::provision()}; every verb is a signal answered under the instance's fence, so the
 * cap is exact by construction under any number of workers, and the whole machine is vars plus signals
 * plus one scheduled sweep, zero engine.
 *
 * The verbs:
 *
 * - `Acquire` answers Granted, Queued, or Rejected through `signalFor`, idempotent by token since the
 *   token IS the waiter's identity;
 *
 * - `Release` is the holder's duty on every exit path, compensation included;
 *
 * - `Renew` is the slow holder's relief against the TTL;
 *
 * - `Withdraw` is the queue abandonment the waiter's own deadline owes.
 *
 * The sweep is the BACKSTOP, not the authority: every verb reaps lazily as it opens the ledger, and
 * the schedule only guarantees a semaphore nobody touches still expropriates leaked grants and
 * promotes the queue.
 *
 * The boundary this class refuses: N calls per second toward a downstream is TRANSPORT concern, a
 * consumer middleware plus rate limiter; a time-based rate held in saga vars would lie under multiple
 * workers, while a concurrency cap under one fence cannot. And a semaphore is a serialization point BY
 * DESIGN, one row's step rate is its throughput ceiling; it guards a scarce resource, it does not
 * belong on a high-frequency hot path.
 *
 * It never settles on its own: a decommission is the operator's `storm:saga:cancel`, and `reuse: Allow`
 * lets the same resource be provisioned again afterward.
 *
 * @see SemaphoreLedger the pure bookkeeping every verb and the sweep drive
 */
#[Workflow(name: 'storm_semaphore', reuse: CorrelationReuse::Allow)]
#[Start(state: 'guard')]
#[State(key: 'guard', type: 'schedule')]
#[Schedule(state: 'guard', intervalVar: SemaphoreLedger::SWEEP_INTERVAL, catchUp: 'skip')]
#[State(key: 'sweep', type: 'activity', activity: SweepActivity::class)]
#[On(from: 'guard', trigger: 'schedule', to: 'sweep')]
#[On(from: 'sweep', trigger: 'success', to: 'guard')]
#[Signal(signal: Acquire::class, handler: 'onAcquire')]
#[Signal(signal: Release::class, handler: 'onRelease')]
#[Signal(signal: Renew::class, handler: 'onRenew')]
#[Signal(signal: Withdraw::class, handler: 'onWithdraw')]
#[ExposesState(
    SemaphoreLedger::RESOURCE,
    SemaphoreLedger::CAPACITY,
    SemaphoreLedger::MAX_QUEUE,
    SemaphoreLedger::GRANT_TTL,
    SemaphoreLedger::QUEUE_TTL,
    SemaphoreLedger::SWEEP_INTERVAL,
    SemaphoreLedger::HOLDERS,
    SemaphoreLedger::QUEUE,
    SemaphoreLedger::EXPROPRIATED,
    SemaphoreLedger::LAPSED,
)]
final readonly class SemaphoreWorkflow
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $vars
     */
    public function onAcquire(Acquire $signal, array $vars): SignalResult
    {
        $ledger = SemaphoreLedger::open($this->clock->now(), $vars);
        $reply = $ledger->acquire($signal->waiterType, $signal->waiterCorrelation, $signal->grantTtlSeconds);

        return SignalResult::stay($ledger->vars(), $ledger->grants(), result: $reply);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function onRelease(Release $signal, array $vars): SignalResult
    {
        $ledger = SemaphoreLedger::open($this->clock->now(), $vars);
        $ledger->release($signal->waiterType, $signal->waiterCorrelation);

        return SignalResult::stay($ledger->vars(), $ledger->grants());
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function onRenew(Renew $signal, array $vars): SignalResult
    {
        $ledger = SemaphoreLedger::open($this->clock->now(), $vars);
        $reply = $ledger->renew($signal->waiterType, $signal->waiterCorrelation, $signal->grantTtlSeconds);

        return SignalResult::stay($ledger->vars(), $ledger->grants(), result: $reply);
    }

    /**
     * The queue-side abandonment shares the release's clearing on purpose: a grant that raced the
     * waiter's own deadline in flight must not survive as a ghost slot until the TTL.
     *
     * @param  array<string, mixed>  $vars
     */
    public function onWithdraw(Withdraw $signal, array $vars): SignalResult
    {
        $ledger = SemaphoreLedger::open($this->clock->now(), $vars);
        $ledger->release($signal->waiterType, $signal->waiterCorrelation);

        return SignalResult::stay($ledger->vars(), $ledger->grants());
    }
}
