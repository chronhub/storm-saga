<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Engine\Run\MachineRun;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Engine\Verdict\Finalize;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Noop;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Event\SagaCompleted;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Event\SagaRetried;
use Storm\Saga\Event\SagaTransitioned;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;

/**
 * The machine's progression state, immutable: at any point it is a complete, consistent snapshot
 * with no half-applied locals in flight. Exactly two transformations, one per semantic moment of a
 * run, crossing an edge and coming to rest, each owning its invariant whole. A transition cancels
 * the left state's timers when it may hold one, as the caller decides, announces itself, moves, and
 * resets the left state's retry budget; this is impossible to do by halves from the outside.
 *
 * - Transition: cross an edge with a Transition verdict and the advanced log.
 * - Rest: settle the run on any resting verdict, producing the MachineRun.
 *
 * The collected effects, the ordered TimerOps, commands, and announcements, ride the cursor as
 * data; the machine does no IO.
 */
final readonly class MachineCursor
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{n: int, since: string|null}>  $retries
     * @param  list<CompensationRecord>  $log
     * @param  list<TimerOp>  $timerOps
     * @param  list<IssuedCommand>  $commands  each wrapped with the state whose verdict yielded it, the outbox row's provenance
     * @param  list<SagaAnnouncement>  $announcements
     */
    private function __construct(
        private WorkflowInstanceRow $origin,
        public string $stateKey,
        public array $vars,
        public array $retries,
        public array $log,
        private array $timerOps,
        private array $commands,
        private array $announcements,
        private bool $advanced,
    ) {}

    public static function at(WorkflowInstanceRow $row): self
    {
        return new self($row, $row->stateKey, $row->vars, $row->retries, $row->compensations, [], [], [], false);
    }

    /**
     * Cross an edge: collect the verdict's commands, cancel the left state's timers as an op when
     * `$cancelLeftTimer`, announce the hop, reset the left state's retry budget, and move to the
     * target with the verdict's vars and the advanced log. The retry budget is per-visit: a re-entry
     * decided by the workflow's own graph starts fresh, and the kicks of one visit accumulated
     * within it.
     *
     * @param  list<CompensationRecord>  $log  the log after Compensator::advanceLog
     * @param  bool  $cancelLeftTimer  whether the left state may hold an armed timer worth a cancel op;
     *                                 false for a state that never armed one, a synchronous intermediate
     *                                 or an untimed wait, so the no-op DELETE is skipped
     */
    public function transition(Transition $verdict, array $log, bool $cancelLeftTimer): self
    {
        $retries = $this->retries;
        unset($retries[$this->stateKey]);

        return new self(
            $this->origin,
            $verdict->to,
            $verdict->vars,
            $retries,
            $log,
            $cancelLeftTimer ? [...$this->timerOps, TimerOp::cancelState($this->stateKey)] : $this->timerOps,
            [...$this->commands, ...$this->issuedBy($this->stateKey, $verdict->commands)],
            [...$this->announcements, new SagaTransitioned($this->origin->workflowType, $this->origin->correlationId, $this->origin->generation, $this->stateKey, $verdict->to)],
            true,
        );
    }

    /**
     * @param  list<object>  $commands
     * @return list<IssuedCommand>
     */
    private function issuedBy(string $stateKey, array $commands): array
    {
        return array_map(static fn (object $command): IssuedCommand => new IssuedCommand($stateKey, $command), $commands);
    }

    /**
     * Settle the run on a resting verdict:
     *
     * | Verdict            | Run               |
     * |--------------------|-------------------|
     * | Stay               | Rested, Running   |
     * | Finalize           | Rested, Completed |
     * | Halt               | Rested, Halted    |
     * | Noop, not advanced | Unmoved           |
     * | Noop, advanced     | Rested, Running   |
     *
     * Stay carries the verdict's authoritative vars, plus retries and ops; Finalize carries the
     * verdict's vars and announces SagaCompleted; Halt carries the accumulated vars and announces
     * SagaHalted only when the log is empty, since a halt with a log is compensated by the caller. A
     * Noop that did not advance carries nothing; a Noop that advanced rested into an untimed wait,
     * carrying the accumulated vars. A Transition cannot be rested on; the native union seals it out
     * at the signature. A Stay also announces SagaRetried when its `retryTotal` is set, a retry that
     * kept budget, the only history trace of a retry since the state does not change.
     */
    public function rest(Stay|Finalize|Halt|Noop $verdict): Rested|Unmoved
    {
        // a crossing anywhere in the run ends the resting deadline's negotiation: the retime budget
        // resets on any ADVANCED rest, including a leave-and-return onto the same wait, which is a
        // new visit all the same; a rest in place, a retry or an async stay, keeps it
        $rested = fn (WorkflowInstanceRow $row): WorkflowInstanceRow => $this->advanced ? $row->retimesReset() : $row;

        return match (true) {
            $verdict instanceof Stay => new Rested(
                $rested($this->origin->restingAt($this->stateKey, WorkflowStatus::Running, $verdict->vars, $verdict->retries ?? $this->retries, $this->log, $verdict->retryTotal)),
                $verdict->retryTotal !== null
                    ? [...$this->announcements, new SagaRetried($this->origin->workflowType, $this->origin->correlationId, $this->origin->generation, $this->stateKey, ($verdict->retries ?? $this->retries)[$this->stateKey]['n'] ?? 0, $verdict->retryTotal)]
                    : $this->announcements,
                [...$this->timerOps, ...$verdict->timerOps],
                [...$this->commands, ...$this->issuedBy($this->stateKey, $verdict->commands)],
            ),
            $verdict instanceof Finalize => new Rested(
                $rested($this->origin->restingAt($this->stateKey, WorkflowStatus::Completed, $verdict->vars, $this->retries, $this->log)),
                [...$this->announcements, new SagaCompleted($this->origin->workflowType, $this->origin->correlationId, $this->origin->generation, $this->stateKey)],
                $this->timerOps,
                $this->commands,
            ),
            $verdict instanceof Halt => new Rested(
                $rested($this->origin->restingAt($this->stateKey, WorkflowStatus::Halted, $this->vars, $this->retries, $this->log)),
                $this->log === []
                    ? [...$this->announcements, new SagaHalted($this->origin->workflowType, $this->origin->correlationId, $this->origin->generation, $this->stateKey)]
                    : $this->announcements,
                $this->timerOps,
                [...$this->commands, ...$this->issuedBy($this->stateKey, $verdict->commands)],
            ),
            $verdict instanceof Noop => $this->advanced
                ? new Rested(
                    $rested($this->origin->restingAt($this->stateKey, WorkflowStatus::Running, $this->vars, $this->retries, $this->log)),
                    $this->announcements,
                    $this->timerOps,
                    $this->commands,
                )
                : new Unmoved,
        };
    }
}
