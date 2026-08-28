<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Event\CompensationFailed;
use Storm\Saga\Event\SagaAnnouncement;
use Storm\Saga\Event\SagaRaceSettled;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\RaceArmSlot;
use Storm\Saga\Workflow\RacePolicy;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The victory's disposition, in the SAME step as the crossing: when a delivered outcome carries the
 * saga out of a race's settling wait, the winner joins the compensation log CONFIRMED, its outcome
 * having just arrived, so a later rollback may undo the kept effect through its own arm. Every
 * losing arm is then disposed of by what its command's evidence allows:
 *
 * - Still `pending` in the outbox: RECALLED, the targeted cancel by effect group; the relay never
 *   claimed it, so nothing happened and nothing needs undoing, and the entry joins settled
 *   `Skipped`, the proven non-event;
 *
 * - Already claimed or published: COMPENSATED on the spot, the arm's undo run like a rollback runs
 *   one, its commands riding the same outbox as forward work; a failed undo is flagged
 *   `CompensationFailed` and left `Failed` for reconciliation, never silently retried.
 *
 * The recall-or-compensate order is the honesty of the thing: the recall's row-lock arbitration
 * against the relay decides which half each loser gets, inside the step's transaction, so the
 * decision and its consequence commit together. The residual the arm signed up for: an undo issued
 * here can reach its handler BEFORE the do it undoes, since a loser that dodged the recall is still
 * traveling; an arm compensation tolerates that, the reconciliation shape at-least-once delivery
 * already demands.
 *
 * A race the saga leaves by anything OTHER than an arm's outcome cannot happen by construction: the
 * build proves the settling wait accepts exactly the arms' outcome classes.
 *
 * @see RaceArm
 * @see Compensator the later-rollback twin this settler pre-dispositions the log for
 */
final readonly class RaceSettler
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private WorkflowOutbox $outbox,
        private Clock $clock,
    ) {}

    /**
     * Dispose of a settled race, or hand the run back untouched: the origin row must rest on some
     * race's settling wait, the stimulus must be a delivered event, an arm must win by it, and the
     * machine must actually have crossed out.
     *
     * @throws SagaStorageFailure when the targeted recall's storage fails; the step rolls back whole
     * @throws ClockExceptionContract when the disposition timestamp cannot be derived
     */
    public function settleIfWon(WorkflowDefinition $def, WorkflowInstanceRow $origin, Stimulus $stimulus, Rested $run, ?string $causationId): Rested
    {
        $event = $stimulus->eventOrNull();
        if ($event === null || $run->row->stateKey === $origin->stateKey) {
            return $run; // not an event, or the wait was not crossed: no victory to dispose of
        }

        [$raceState, $race] = $this->raceSettledAt($def, $origin->stateKey) ?? [null, null];
        if ($raceState === null || $race === null) {
            return $run;
        }

        $winner = $race->winnerFor($event::class);
        if ($winner === null) {
            return $run; // defensive: the build proves the wait accepts only arm outcomes
        }

        $now = $this->clock->now()->toString();
        $vars = $run->row->vars;
        $commands = $run->commands;
        $events = $run->announcements;
        $log = $run->row->compensations;

        // the winner's effect is KEPT, and its outcome just arrived: the entry joins confirmed, so a
        // later rollback undoes it through ITS arm, never through a state-level guess
        $log = [...$log, CompensationRecord::forArm($raceState->key, $winner->name, CompensationStatus::Pending, confirmed: true, at: $now)];

        $recalled = [];
        $compensated = [];
        $failed = [];
        $metadata = new Metadata($origin->workflowType, $origin->correlationId, $causationId, $origin->context);

        foreach ($race->losersTo($winner) as $loser) {
            if ($this->outbox->cancelPending($origin->id(), $loser->name) > 0) {
                // never claimed by the relay: the row-lock arbitration proves nothing was dispatched,
                // so there is nothing to undo; the one loser whose non-event is PROVEN
                $log = [...$log, CompensationRecord::forArm($raceState->key, $loser->name, CompensationStatus::Skipped, confirmed: false, reason: 'recalled: never dispatched', at: $now)];
                $recalled[] = $loser->name;

                continue;
            }

            [$log, $vars, $commands, $events, $undone] = $this->undoInFlight($raceState->key, $loser, $vars, $metadata, $log, $commands, $events, $origin, $now);
            $undone ? $compensated[] = $loser->name : $failed[] = $loser->name;
        }

        $events[] = new SagaRaceSettled($origin->workflowType, $origin->correlationId, $origin->generation, $raceState->key, $winner->name, $recalled, $compensated, $failed);

        return new Rested(
            $run->row->restingAt($run->row->stateKey, $run->row->status, $vars, $run->row->retries, $log),
            $events,
            $run->timerOps,
            $commands,
        );
    }

    /**
     * The race whose settling wait is `$waitKey`, with its issuing state; null when no race settles
     * there. The build proves at most one: two races could only share a wait by sharing a success
     * edge target, and their arm outcome sets would then collide on the exact-coverage rule.
     *
     * @return array{ActivityState, RacePolicy}|null
     */
    private function raceSettledAt(WorkflowDefinition $def, string $waitKey): ?array
    {
        foreach ($def->states() as $state) {
            if ($state instanceof ActivityState && $state->race !== null && $state->race->settledBy === $waitKey) {
                return [$state, $state->race];
            }
        }

        return null;
    }

    /**
     * The in-flight loser's immediate undo, the Compensator's per-entry discipline reproduced at the
     * victory: a successful undo adopts the returned vars and rides its commands out through the
     * outbox stamped with the ARM's group; a refusal or a throw settles the entry `Failed` and
     * announces `CompensationFailed`, reconciliation's cue, never a silent retry.
     *
     * @param  array<string, mixed>  $vars
     * @param  list<CompensationRecord>  $log
     * @param  list<IssuedCommand>  $commands
     * @param  list<SagaAnnouncement>  $events
     * @return array{list<CompensationRecord>, array<string, mixed>, list<IssuedCommand>, list<SagaAnnouncement>, bool}
     */
    private function undoInFlight(string $stateKey, RaceArmSlot $loser, array $vars, Metadata $metadata, array $log, array $commands, array $events, WorkflowInstanceRow $origin, string $now): array
    {
        try {
            $outcome = $loser->compensation->run($vars, $metadata);
            if ($outcome->outcome === ActivityOutcome::Success) {
                $commands = [...$commands, ...array_map(static fn (object $command): IssuedCommand => new IssuedCommand($stateKey, $command, $loser->name), $outcome->commands)];
                $log = [...$log, CompensationRecord::forArm($stateKey, $loser->name, CompensationStatus::Compensated, confirmed: false, at: $now)];

                return [$log, $outcome->vars, $commands, $events, true];
            }

            $error = $outcome->error ?? 'compensation failed';
        } catch (Throwable $e) {
            $error = AuditDigest::digest($e); // bounded, scrubbed: persisted and served over the ops surface
        }

        $log = [...$log, CompensationRecord::forArm($stateKey, $loser->name, CompensationStatus::Failed, confirmed: false, reason: $error, at: $now)];
        $events[] = new CompensationFailed($origin->workflowType, $origin->correlationId, $origin->generation, $stateKey.'#'.$loser->name, $error);

        return [$log, $vars, $commands, $events, false];
    }
}
