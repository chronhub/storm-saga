<?php

declare(strict_types=1);

namespace Storm\Saga\Schedule;

use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\SagaTimerTarget;
use Storm\Saga\Exception\MissingAsyncTimeout;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\UnknownState;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowStepLimitExceeded;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Store\DueTimerQueue;
use Storm\Saga\Store\TimerKind;
use Storm\Saga\Store\WorkflowTimerStore;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The saga recovery agent: a claim-loop over `workflow_timers`. Each tick claims the due leased rows and
 * drives each into the engine inline, by kind:
 *
 * - `Timeout` fires the state's timeout transition via `Engine::timeout()`.
 *
 * - `Kick` re-runs the state for a retry back-off via `Engine::kick()`.
 *
 * - `Schedule` fires a due cadence slot and re-arms the next via `Engine::schedule()`, passing the
 *   timer's `fire_at` as the slot instant.
 *
 * - `Global` fires the instance-wide deadline via `Engine::globalTimeout()`.
 *
 * The state-scoped kinds `Timeout`, `Kick` and `Schedule` pass the timer's `state_key` as the engine's
 * stale-state guard, so a timer whose instance already moved on is a no-op; the `Global` deadline is
 * instance-wide and carries no such guard.
 *
 * Inline and synchronous, like the projector runner and the outbox relay, Storm's other claim-loops, not
 * a Messenger fan-out. Because the claim is a lease and a handled timer is removed by the step, a crashed
 * or fence-raced timer is retried on a later tick: that re-claim IS the durability, a lost timer
 * re-dispatched with no dedicated agent. The signal-aware long-running command driving `tick()` is wired
 * with the engine.
 *
 * @see WorkflowTimerStore::claimDue()
 * @see Engine::timeout()
 * @see Engine::kick()
 * @see Engine::schedule()
 * @see Engine::globalTimeout()
 */
final readonly class TimerRunner
{
    /**
     * Transient failures tolerated per batch before the abort: small on purpose, enough that a
     * couple of flaky rows cannot stall their siblings, few enough that a shared cause still
     * bails fast.
     */
    private const int TRANSIENT_CONTINUES_PER_BATCH = 3;

    /**
     * @param  Clock<PointInTime>  $clock
     * @param  positive-int  $leaseSeconds  how long a claimed-but-unprocessed timer waits before re-claim
     */
    public function __construct(
        private DueTimerQueue $timers,
        private SagaTimerTarget $engine,
        private Clock $clock,
        // @infection-ignore-all; equivalent: the bundle always sets this argument, so the default serves a standalone construction no production wiring takes
        private int $leaseSeconds = 300,
        private int $maxTimerAttempts = 5,
    ) {}

    /**
     * Claim and drive one batch of due timers. Returns how many were claimed this pass.
     *
     * Failure discipline, per row:
     *
     *  - A permanent failure, where the timer names a workflow or pinned version no longer registered, a
     *    purge that outran `storm:saga:versions --check`, PARKS the row immediately and the batch
     *    CONTINUES: the row can never drive, so retrying it would crash-loop the daemon while its
     *    co-claimed siblings wait out the lease, forever.
     *
     *  - Any other throw is treated as transient: the failure is recorded on the row, with attempts and
     *    forensics, and the batch CONTINUES for a bounded allowance of such rows before bailing; the
     *    skipped row stays claimed and the lease retries it. Without the allowance, one flaky row held
     *    its co-claimed siblings for the full lease and could only advance its budget once per lease
     *    window, up to `leaseSeconds x maxTimerAttempts` of repeated sibling stalls before parking.
     *    Past the allowance the throw propagates and the batch aborts, the recovery-agent contract:
     *    that many transients in one batch reads as a shared cause, a blinking database, where
     *    skipping on would only fail every remaining row too. The bound degrades gracefully to the
     *    same bail when the SHARED cause breaks `recordFailure` itself, whose own throw is never
     *    caught. A row that keeps failing exhausts its budget of `$maxTimerAttempts` and is parked,
     *    quarantined and visible, no longer the daemon's poison.
     *
     *  - A drive that returns `false`, a raced fence or a stale state, is NOT a failure: the row stays
     *    claimed and the lease retries it for free.
     *
     * @param  positive-int  $batch
     *
     * @throws SagaStorageFailure when the saga storage fails claiming the due rows or driving a step
     * @throws InvalidDateTimeException when the claim's lease cutoff / a timer instant cannot be derived
     * @throws StaleWorkflowInstance when a driven step's OCC update loses to a competing step
     * @throws WorkflowStepLimitExceeded when a driven step's synchronous transition chain cycles
     * @throws UnknownState when a driven transition targets an undeclared state
     * @throws MissingAsyncTimeout when a driven async activity state declares no timeout
     * @throws SerializationExceptionContract when a driven step's issued command is not a serializable payload
     * @throws ClockExceptionContract when the clock yields a non-canonical instant
     * @throws Throwable when a timer's drive throws a TRANSIENT exception within budget, past the
     *                   batch's bounded allowance
     */
    public function tick(int $batch = 100): TimerTick
    {
        $due = $this->timers->claimDue($batch, $this->clock->now(), $this->leaseSeconds);
        $transientAllowance = self::TRANSIENT_CONTINUES_PER_BATCH;

        foreach ($due as $timer) {
            try {
                match ($timer->kind) {
                    TimerKind::Timeout => $this->engine->timeout($timer->workflowType, $timer->correlationId, $timer->stateKey),
                    TimerKind::Kick => $this->engine->kick($timer->workflowType, $timer->correlationId, $timer->stateKey),
                    TimerKind::Schedule => $this->engine->schedule($timer->workflowType, $timer->correlationId, $timer->stateKey, $timer->fireAt),
                    TimerKind::Global => $this->engine->globalTimeout($timer->workflowType, $timer->correlationId),
                };
            } catch (WorkflowNotFound|WorkflowVersionNotFound $e) {
                // permanent: orphaned by a purge; this row can never drive; park it and free the batch
                $this->timers->park($timer->id, AuditDigest::digest($e));

                continue;
            } catch (Throwable $e) {
                if ($this->timers->recordFailure($timer->id, AuditDigest::digest($e)) >= $this->maxTimerAttempts) {
                    // the budget is spent: quarantine instead of crash-looping; visible, not the daemon's poison
                    $this->timers->park($timer->id, AuditDigest::digest($e));

                    continue;
                }

                if ($transientAllowance-- > 0) {
                    // bounded continue: the failure is recorded, the row stays claimed for the lease's
                    // retry, and one flaky row does not hold its co-claimed siblings for the window
                    continue;
                }

                throw $e; // past the allowance a shared cause is the likelier read: the bail IS the retry
            }
        }

        // A full claim means the cap cut the pass, not that nothing more is due, and running timers
        // CREATES relay work, so a drain that reads a green line here is not finished at all.
        return new TimerTick(count($due), count($due) === $batch);
    }
}
