<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Run\Unmoved;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Event\CompensationFailed;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Event\SagaJoinArmArrived;
use Storm\Saga\Event\SagaJoinCompleted;
use Storm\Saga\Event\SagaJoinFailed;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\JoinArmSlot;
use Storm\Saga\Workflow\JoinPolicy;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The join's accountant, on both sides of the machine.
 *
 * BEFORE the machine, {@see gateArrival()} keeps a partial completion from crossing: the arm is
 * marked in the row's `arms` ledger, its vars land exactly as the wait would land them, its entry
 * joins the compensation log CONFIRMED since its outcome just arrived, and the saga rests where it
 * stands, the wait's standing timer untouched. Partial arrivals do not feed the heartbeat: liveness
 * is judged on the JOIN, not per arm. A redelivered outcome, its arm already marked, is absorbed;
 * so is a failure event condemning an arm that already completed, since an arm's first verdict
 * stands. Everything else is the machine's to route through the graph's own edges: the LAST
 * completion, a first failure, a foreign event.
 *
 * AFTER the machine, {@see settle()} accounts for the crossing it let through. The last completion
 * marks its arm and logs it confirmed like every arrival before it. A definitive arm failure, out
 * through the failure event's own `#[On(onEvent:)]` edge, disposes of the WHOLE join in the same
 * step, each arm by what its evidence allows: an arm already completed is compensated through ITS
 * `compensate` and its confirmed entry settles; a command still pending in the outbox is RECALLED,
 * the proven non-event; one in flight with no outcome yet is compensated on the spot, the
 * undo-before-do clause every arm signed. A failed undo is flagged `CompensationFailed` and left
 * `Failed` for reconciliation, never silently retried.
 *
 * @see JoinArm
 * @see RaceSettler the first-outcome dual this settler mirrors
 */
final readonly class JoinSettler
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private WorkflowOutbox $outbox,
        private Clock $clock,
        private WaitVarExtractor $extraction,
    ) {}

    /**
     * Judge a delivered event against the join whose wait the row rests on, BEFORE the machine
     * runs: a partial completion comes back `Rested` in place, a duplicate or contradictory arrival
     * comes back `Unmoved`, and null hands the stimulus to the machine: no join here, the last
     * completion, a first failure, or an event the wait's own matcher would refuse.
     *
     * @throws ClockExceptionContract when the arrival timestamp cannot be derived
     * @throws MissingExtractField when the wait's extract map names a field the outcome's payload does not carry
     */
    public function gateArrival(WorkflowDefinition $def, WorkflowInstanceRow $row, Stimulus $stimulus): Rested|Unmoved|null
    {
        $event = $stimulus->eventOrNull();
        if ($event === null) {
            return null;
        }

        [$joinState, $join] = $this->joinedAt($def, $row->stateKey) ?? [null, null];
        if ($joinState === null || $join === null) {
            return null;
        }

        $arrived = $row->arms[$joinState->key] ?? [];

        $completer = $join->completerFor($event::class);
        if ($completer === null) {
            // a failure condemning an arm that already COMPLETED is contradictory late noise: the
            // arm's first verdict stands, the event is absorbed. A FIRST failure runs the machine.
            $failer = $join->failerFor($event::class);

            return $failer !== null && in_array($failer->name, $arrived, true) ? new Unmoved : null;
        }

        if (in_array($completer->name, $arrived, true)) {
            return new Unmoved; // a redelivered outcome: marked once, marked forever
        }

        $wait = $def->state($row->stateKey);
        if (! $wait instanceof WaitState) {
            return null; // defensive: the build proves the joining wait is a wait
        }
        if ($wait->matcher !== null && ! ($wait->matcher)($event, $row->vars)) {
            return null; // the wait's own matcher refuses it; the machine will Noop identically
        }

        if ($join->isComplete([...$arrived, $completer->name])) {
            return null; // the LAST completion: the machine crosses, settle() accounts for it
        }

        $now = $this->clock->now()->toString();
        $resting = $row->armArrived($joinState->key, $completer->name)->restingAt(
            $row->stateKey,
            $row->status,
            $this->extraction->apply($wait, $event, $row->vars),
            $row->retries,
            [...$row->compensations, CompensationRecord::forArm($joinState->key, $completer->name, CompensationStatus::Pending, confirmed: true, at: $now)],
        );

        $remaining = array_values(array_diff(array_keys($join->arms), [...$arrived, $completer->name]));

        return new Rested($resting, [
            new SagaJoinArmArrived($row->workflowType, $row->correlationId, $row->generation, $joinState->key, $completer->name, $remaining),
        ]);
    }

    /**
     * Account for a crossing out of a joining wait, or hand the run back untouched: the last
     * completion joins the ledger and the log like every arrival before it; a definitive arm
     * failure disposes of the whole join by each arm's evidence.
     *
     * @throws SagaStorageFailure when the targeted recall's storage fails; the step rolls back whole
     * @throws ClockExceptionContract when the disposition timestamp cannot be derived
     */
    public function settle(WorkflowDefinition $def, WorkflowInstanceRow $origin, Stimulus $stimulus, Rested $run, ?string $causationId): Rested
    {
        $event = $stimulus->eventOrNull();
        if ($event === null || $run->row->stateKey === $origin->stateKey) {
            return $run; // not an event, or the wait was not crossed: nothing to account for
        }

        [$joinState, $join] = $this->joinedAt($def, $origin->stateKey) ?? [null, null];
        if ($joinState === null || $join === null) {
            return $run;
        }

        $completer = $join->completerFor($event::class);
        if ($completer !== null) {
            return $this->completed($joinState, $join, $completer, $run);
        }

        $failer = $join->failerFor($event::class);
        if ($failer !== null) {
            return $this->disposed($joinState, $join, $failer, $origin, $run, $causationId);
        }

        return $run; // defensive: the build proves the wait accepts only arm events
    }

    /**
     * The join whose joining wait is `$waitKey`, with its issuing state; null when no join
     * completes there. The build proves at most one: two joins could only share a wait by sharing a
     * success edge target, and their arm event sets would then collide on the exact-coverage rule.
     *
     * @return array{ActivityState, JoinPolicy}|null
     */
    private function joinedAt(WorkflowDefinition $def, string $waitKey): ?array
    {
        foreach ($def->states() as $state) {
            if ($state instanceof ActivityState && $state->join !== null && $state->join->joinedBy === $waitKey) {
                return [$state, $state->join];
            }
        }

        return null;
    }

    /**
     * The LAST completion crossed: its arm joins the ledger and the log confirmed, symmetric with
     * every arrival before it, and the join announces itself complete.
     *
     * @throws ClockExceptionContract
     */
    private function completed(ActivityState $joinState, JoinPolicy $join, JoinArmSlot $completer, Rested $run): Rested
    {
        $now = $this->clock->now()->toString();
        $row = $run->row->armArrived($joinState->key, $completer->name);
        $row = $row->restingAt(
            $row->stateKey,
            $row->status,
            $row->vars,
            $row->retries,
            [...$row->compensations, CompensationRecord::forArm($joinState->key, $completer->name, CompensationStatus::Pending, confirmed: true, at: $now)],
        );

        return new Rested(
            $row,
            [...$run->announcements, new SagaJoinCompleted($row->workflowType, $row->correlationId, $row->generation, $joinState->key, array_keys($join->arms))],
            $run->timerOps,
            $run->commands,
        );
    }

    /**
     * A definitive arm failure crossed, out through its own edge: dispose of every sibling by what
     * its evidence allows, inside this step's transaction, so the verdict and its consequences
     * commit together.
     *
     * @throws SagaStorageFailure
     * @throws ClockExceptionContract
     */
    private function disposed(ActivityState $joinState, JoinPolicy $join, JoinArmSlot $failer, WorkflowInstanceRow $origin, Rested $run, ?string $causationId): Rested
    {
        $now = $this->clock->now()->toString();
        $vars = $run->row->vars;
        $commands = $run->commands;
        $events = $run->announcements;
        $log = $run->row->compensations;
        $arrived = $origin->arms[$joinState->key] ?? [];
        $metadata = new Metadata($origin->workflowType, $origin->correlationId, $causationId, $origin->context);

        // the failed arm itself has nothing to undo: its downstream reported the definitive refusal
        $log = [...$log, CompensationRecord::forArm($joinState->key, $failer->name, CompensationStatus::Skipped, confirmed: false, reason: 'failed: nothing to undo', at: $now)];

        $compensated = [];
        $recalled = [];
        $failedUndos = [];

        foreach ($join->arms as $arm) {
            if ($arm->name === $failer->name) {
                continue;
            }

            if (in_array($arm->name, $arrived, true)) {
                // completed earlier, its confirmed entry Pending in the log: the undo runs on the
                // spot and the SAME entry settles; appending a sibling would leave two truths
                [$log, $vars, $commands, $events, $undone] = $this->undoArrived($joinState->key, $arm, $vars, $metadata, $log, $commands, $events, $origin, $now);
                $undone ? $compensated[] = $arm->name : $failedUndos[] = $arm->name;

                continue;
            }

            if ($this->outbox->cancelPending($origin->id(), $arm->name) > 0) {
                // never claimed by the relay: the row-lock arbitration proves nothing was dispatched,
                // so there is nothing to undo; the one sibling whose non-event is PROVEN
                $log = [...$log, CompensationRecord::forArm($joinState->key, $arm->name, CompensationStatus::Skipped, confirmed: false, reason: 'recalled: never dispatched', at: $now)];
                $recalled[] = $arm->name;

                continue;
            }

            // in flight, no outcome yet: the undo-before-do clause every arm signed
            [$log, $vars, $commands, $events, $undone] = $this->undoInFlight($joinState->key, $arm, $vars, $metadata, $log, $commands, $events, $origin, $now);
            $undone ? $compensated[] = $arm->name : $failedUndos[] = $arm->name;
        }

        $events[] = new SagaJoinFailed($origin->workflowType, $origin->correlationId, $origin->generation, $joinState->key, $failer->name, $compensated, $recalled, $failedUndos);

        return new Rested(
            $run->row->restingAt($run->row->stateKey, $run->row->status, $vars, $run->row->retries, $log),
            $events,
            $run->timerOps,
            $commands,
        );
    }

    /**
     * Undo an arm that COMPLETED before a sibling failed: its confirmed `Pending` entry settles to
     * the undo's verdict in place, by composite key, never appended beside.
     *
     * @param  array<string, mixed>  $vars
     * @param  list<CompensationRecord>  $log
     * @param  list<IssuedCommand>  $commands
     * @param  list<SagaAnnouncement>  $events
     * @return array{list<CompensationRecord>, array<string, mixed>, list<IssuedCommand>, list<SagaAnnouncement>, bool}
     */
    private function undoArrived(string $stateKey, JoinArmSlot $arm, array $vars, Metadata $metadata, array $log, array $commands, array $events, WorkflowInstanceRow $origin, string $now): array
    {
        try {
            $outcome = $arm->compensation->run($vars, $metadata);
            if ($outcome->outcome === ActivityOutcome::Success) {
                $commands = [...$commands, ...array_map(static fn (object $command): IssuedCommand => new IssuedCommand($stateKey, $command, $arm->name), $outcome->commands)];

                return [$this->settleEntry($log, $stateKey.'#'.$arm->name, CompensationStatus::Compensated, null, $now), $outcome->vars, $commands, $events, true];
            }

            $error = $outcome->error ?? 'compensation failed';
        } catch (Throwable $e) {
            $error = AuditDigest::digest($e); // bounded, scrubbed: persisted and served over the ops surface
        }

        $events[] = new CompensationFailed($origin->workflowType, $origin->correlationId, $origin->generation, $stateKey.'#'.$arm->name, $error);

        return [$this->settleEntry($log, $stateKey.'#'.$arm->name, CompensationStatus::Failed, $error, $now), $vars, $commands, $events, false];
    }

    /**
     * Undo an arm still IN FLIGHT, no outcome and no log entry yet: the entry is appended already
     * settled by this disposition, the RaceSettler's in-flight discipline verbatim.
     *
     * @param  array<string, mixed>  $vars
     * @param  list<CompensationRecord>  $log
     * @param  list<IssuedCommand>  $commands
     * @param  list<SagaAnnouncement>  $events
     * @return array{list<CompensationRecord>, array<string, mixed>, list<IssuedCommand>, list<SagaAnnouncement>, bool}
     */
    private function undoInFlight(string $stateKey, JoinArmSlot $arm, array $vars, Metadata $metadata, array $log, array $commands, array $events, WorkflowInstanceRow $origin, string $now): array
    {
        try {
            $outcome = $arm->compensation->run($vars, $metadata);
            if ($outcome->outcome === ActivityOutcome::Success) {
                $commands = [...$commands, ...array_map(static fn (object $command): IssuedCommand => new IssuedCommand($stateKey, $command, $arm->name), $outcome->commands)];
                $log = [...$log, CompensationRecord::forArm($stateKey, $arm->name, CompensationStatus::Compensated, confirmed: false, at: $now)];

                return [$log, $outcome->vars, $commands, $events, true];
            }

            $error = $outcome->error ?? 'compensation failed';
        } catch (Throwable $e) {
            $error = AuditDigest::digest($e); // bounded, scrubbed: persisted and served over the ops surface
        }

        $log = [...$log, CompensationRecord::forArm($stateKey, $arm->name, CompensationStatus::Failed, confirmed: false, reason: $error, at: $now)];
        $events[] = new CompensationFailed($origin->workflowType, $origin->correlationId, $origin->generation, $stateKey.'#'.$arm->name, $error);

        return [$log, $vars, $commands, $events, false];
    }

    /**
     * Settle the ONE `Pending` entry matching `$key` in place; any other status is already a
     * verdict and stands, the Compensator's own guard mirrored here.
     *
     * @param  list<CompensationRecord>  $log
     * @return list<CompensationRecord>
     */
    private function settleEntry(array $log, string $key, CompensationStatus $status, ?string $reason, string $now): array
    {
        return array_map(
            static fn (CompensationRecord $record): CompensationRecord => $record->key() === $key && $record->status === CompensationStatus::Pending
                ? $record->settle($status, $reason, $now)
                : $record,
            $log,
        );
    }
}
