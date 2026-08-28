<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Event\CompensationFailed;
use Storm\Saga\Event\SagaCompensated;
use Storm\Saga\Event\SagaCompensationSkipped;
use Storm\Saga\Event\SagaHalted;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\CompensationMode;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\State;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The compensation-log semantics, all of them, forward and backward: the log's owner is one class.
 * Pure of persistence: every method takes the loaded definition, row, and log and returns intent, an
 * updated log, a verdict, or a Rested run; the caller persists and dispatches. Four seams:
 *
 *  - `advanceLog`, the forward side: a completed compensatable step joins the log as intent, and a
 *    success event confirms the steps that named it in `#[Compensate(confirmedBy:)]`.
 *
 *  - `hasConfirmedCompensation`: at a global deadline, is anything safe to undo?
 *
 *  - `compensate` and `maybeCompensate`: run the eligible compensations in reverse and return the
 *    rested run.
 *
 *  - `stepKeys`: the logged step keys, for the skipped-flag event.
 *
 * @see StepExecutor
 */
final readonly class Compensator
{
    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function __construct(
        private Clock $clock,
    ) {}

    /**
     * The log's forward side, in one place: crossing an edge advances the log. A `Success` leave of a
     * compensatable activity logs the step as intent, `Pending`, degraded when the success was
     * fallback salvage; an `Event`-triggered transition lets the delivered success event confirm the
     * steps that named it in `#[Compensate(confirmedBy:)]`, so the effect is now known to have
     * happened. Pure: `now` is a value.
     *
     * The trigger is named by CLASS, never by object: confirmation reads nothing else of an event,
     * so a crossing a family gate rested and replays confirms exactly what the first arrival would
     * have confirmed.
     *
     * @param  class-string|null  $eventClass
     * @param  list<CompensationRecord>  $log
     * @return list<CompensationRecord>
     */
    public function advanceLog(WorkflowDefinition $def, State $left, Transition $verdict, ?string $eventClass, array $log, PointInTime $now): array
    {
        if ($verdict->trigger === OnTrigger::Success && $left instanceof ActivityState && $left->compensation !== null) {
            $log = [...$log, CompensationRecord::pending($left->key, $now->toString(), $verdict->degraded)];
        }

        if ($verdict->trigger === OnTrigger::Event && $eventClass !== null) {
            $log = $this->confirmSteps($def, $log, $eventClass, $now->toString());
        }

        return $log;
    }

    /**
     * Flip any logged entry to confirmed whose step named this just-delivered success event in its
     * `#[Compensate(confirmedBy:)]`.
     *
     * @param  list<CompensationRecord>  $compensations
     * @param  class-string  $eventClass
     * @return list<CompensationRecord>
     */
    private function confirmSteps(WorkflowDefinition $def, array $compensations, string $eventClass, string $now): array
    {
        return array_map(function (CompensationRecord $record) use ($def, $eventClass, $now): CompensationRecord {
            if ($record->confirmed || $record->status !== CompensationStatus::Pending) {
                return $record; // confirmed already, or dispositioned at a race's victory: nothing to flip
            }
            $state = $def->hasState($record->step) ? $def->state($record->step) : null;
            if (! $state instanceof ActivityState) {
                return $record;
            }
            // is_a, not ===, mirrors the wait's `instanceof` match in WaitRunner: a confirmedBy declared as an
            // interface or parent confirms on a concrete subtype event, exactly as the wait advances on it;
            // otherwise the wait advances but the record never flips to confirmed, and a later location-agnostic
            // rollback would skip a real, committed effect as "unconfirmed".
            $trackedBy = $this->trackedBy($state, $record);
            if ($trackedBy !== null && is_a($eventClass, $trackedBy, true)) {
                return $record->confirm($now);
            }

            return $record;
        }, $compensations);
    }

    /**
     * Whether any logged step is safe to undo at a global deadline, meaning its effect is confirmed.
     */
    public function hasConfirmedCompensation(WorkflowDefinition $def, WorkflowInstanceRow $row): bool
    {
        foreach ($row->compensations as $record) {
            if ($record->status !== CompensationStatus::Pending) {
                continue; // already dispositioned, a race loser settled at the victory; terminal either way
            }
            $state = $def->hasState($record->step) ? $def->state($record->step) : null;
            if ($state instanceof ActivityState && $this->undoFor($state, $record) !== null
                && $this->eligibleToCompensate($state, $record, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CompensationRecord>  $records
     * @return list<string> the logged step keys, in completion order
     */
    public function stepKeys(array $records): array
    {
        return array_map(static fn (CompensationRecord $record): string => $record->step, $records);
    }

    /**
     * If the machine halted with completed compensatable steps, roll them back instead of just
     * halting; otherwise return the rested run unchanged.
     *
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     */
    public function maybeCompensate(WorkflowDefinition $def, Rested $run, ?string $causationId): Rested
    {
        if ($run->row->status !== WorkflowStatus::Halted || $run->row->compensations === []) {
            return $run;
        }

        return $this->compensate($def, $run->row, $run, $causationId, locationAgnostic: false);
    }

    /**
     * Run the completed steps' compensations in reverse, each in the step's transaction. Three twists
     * over the naive "undo everything":
     *
     *  - Conditional: a step is only undone if it is eligible, per `eligibleToCompensate()`. At a
     *    positional halt, progression implies confirmation so untracked steps undo too; at a
     *    `$locationAgnostic` global deadline, only steps whose effect was confirmed undo, and the
     *    rest are `CompensationStatus::Skipped`, flagged via `SagaCompensationSkipped`.
     *
     *  - Mode: a failed undo is flagged via `CompensationFailed`, and the rollback either continues
     *    under `BestEffort`, or stops at the first failure and leaves the rest `Pending` under `Strict`.
     *
     *  - Audit: each entry resolves to its terminal `CompensationStatus`, kept in the `compensations`
     *    log, so the persisted row is a per-step audit trail.
     *
     * The saga ends `Compensated` if any undo was attempted, meaning it ran and either succeeded or
     * failed-and-flagged; otherwise `Halted`, where everything was skipped and nothing was safe to
     * undo. A compensation may issue commands like a forward activity, collected and ridden over the
     * command outbox, so the undo of a command-driven step is itself a durable async command.
     *
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     *
     * @see StepExecutor::applyEffects()
     */
    public function compensate(WorkflowDefinition $def, WorkflowInstanceRow $halted, Rested $carried, ?string $causationId, bool $locationAgnostic): Rested
    {
        $type = $halted->workflowType;
        $corr = $halted->correlationId;
        $vars = $halted->vars;
        $events = $carried->announcements;
        $commands = $carried->commands;
        $metadata = new Metadata($type, $corr, $causationId, $halted->context);
        $now = $this->clock->now()->toString();

        /** @var array<string, CompensationRecord> $resolved  each step to its updated entry */
        $resolved = [];
        $ran = [];     // steps whose undo was attempted, succeeded or failed-and-flagged, reverse order
        $skipped = []; // steps not undone: effect unconfirmed / unverifiable here
        $stopped = false; // a strict-mode rollback halted at the first failed undo

        foreach (array_reverse($halted->compensations) as $record) {
            if ($record->status !== CompensationStatus::Pending) {
                // terminal already: a race loser was dispositioned AT the victory, recalled or
                // undone on the spot, and re-walking it here would undo an undo
                continue;
            }
            $state = $def->hasState($record->step) ? $def->state($record->step) : null;
            $undo = $state instanceof ActivityState ? $this->undoFor($state, $record) : null;
            if (! $state instanceof ActivityState || $undo === null) {
                continue; // defensive: only logged states with a compensation
            }

            if ($stopped) {
                // strict mode: leave the remaining earlier steps Pending for reconciliation.
                // @infection-ignore-all; equivalent, continue to break: once stopped, every later
                // entry only READS before reaching here, `hasState()`, `state()` and `undoFor()`
                // being pure lookups, so ending the loop yields the identical result
                continue;
            }

            if (! $this->eligibleToCompensate($state, $record, $locationAgnostic)) {
                $reason = match (true) {
                    $record->degraded => 'degraded — fallback result, undo may not match',
                    $this->trackedBy($state, $record) === null => 'unverifiable at global deadline',
                    default => 'unconfirmed',
                };
                $resolved[$record->key()] = $record->settle(CompensationStatus::Skipped, $reason, $now);
                $skipped[] = $record->key();

                continue;
            }

            $ran[] = $record->key();
            try {
                $outcome = $undo->run($vars, $metadata);
                if ($outcome->outcome === ActivityOutcome::Success) {
                    $vars = $outcome->vars;
                    // the undo is itself durable: its issued commands ride the outbox, like the forward path;
                    // provenance is the compensated step, the state whose rollback issued them
                    $commands = [...$commands, ...array_map(static fn (object $command): IssuedCommand => new IssuedCommand($record->step, $command, $record->arm), $outcome->commands)];
                    $resolved[$record->key()] = $record->settle(CompensationStatus::Compensated, null, $now);
                } else {
                    $error = $outcome->error ?? 'compensation failed';
                    $resolved[$record->key()] = $record->settle(CompensationStatus::Failed, $error, $now);
                    $events[] = new CompensationFailed($type, $corr, $halted->generation, $record->key(), $error);
                    $stopped = $def->compensation === CompensationMode::Strict;
                }
            } catch (Throwable $e) {
                // digested, not raw: the reason persists in the log and the history trail and is
                // served over the ops HTTP surface, so it must be bounded, valid UTF-8, and carry
                // the class and cause chain a reconciliation actually needs
                $error = AuditDigest::digest($e);
                $resolved[$record->key()] = $record->settle(CompensationStatus::Failed, $error, $now);
                $events[] = new CompensationFailed($type, $corr, $halted->generation, $record->key(), $error);
                $stopped = $def->compensation === CompensationMode::Strict;
            }
        }

        // rebuild the log in completion order, swapping in each resolved entry; the key is (step, arm),
        // since a race logs siblings under one state key and the bare step would swap one over the other
        $log = array_map(static fn (CompensationRecord $r): CompensationRecord => $resolved[$r->key()] ?? $r, $halted->compensations);

        if ($ran !== []) {
            $events[] = new SagaCompensated($type, $corr, $halted->generation, $ran);
        }
        if ($skipped !== []) {
            $events[] = new SagaCompensationSkipped($type, $corr, $halted->generation, $skipped);
        }

        // an attempted undo yields Compensated, failures flagged not fatal; everything skipped is a dead end
        $status = $ran !== [] ? WorkflowStatus::Compensated : WorkflowStatus::Halted;
        if ($status === WorkflowStatus::Halted) {
            $events[] = new SagaHalted($type, $corr, $halted->generation, $halted->stateKey);
        }

        return new Rested($halted->settled($status, $vars, $log), $events, [], $commands);
    }

    /**
     * Whether a logged step may be undone now. At a positional halt, the saga's progression past the
     * step already implies its effect happened, so an untracked step with no `confirmedBy` is
     * eligible too, and only a tracked-but-unconfirmed step is skipped. At a location-agnostic global
     * deadline, intent does not equal confirmed effect, so only an explicitly confirmed step is
     * eligible; untracked ones are unverifiable here, hence skipped, the option-A safety scoped to
     * the unconfirmable.
     *
     * A degraded step, success salvaged by a fallback, is never eligible: its real effect is
     * uncertain, since a static default did nothing and an alternative activity's effect will not
     * match the primary's undo.
     */
    private function eligibleToCompensate(ActivityState $state, CompensationRecord $record, bool $locationAgnostic): bool
    {
        if ($record->degraded) {
            return false;
        }

        if ($locationAgnostic) {
            return $record->confirmed;
        }

        return $this->trackedBy($state, $record) === null || $record->confirmed;
    }

    /**
     * The undo an entry resolves to: the arm's own compensation for a race or join entry, the state's
     * `#[Compensate]` activity otherwise. Null when the entry names an arm the pinned definition no
     * longer declares, corruption-shaped, skipped defensively like an unknown step.
     */
    private function undoFor(ActivityState $state, CompensationRecord $record): ?Activity
    {
        if ($record->arm !== null) {
            // every `?->` on this pair is an equivalent mutant to a plain `->`: race and join are
            // mutually exclusive by ActivityState's own contract, so dropping the nullsafe on
            // either side only trades a silent PHP 8 warning for the same null the safe chain
            // already returns, never a different value
            return ($state->race?->arms[$record->arm] ?? null)?->compensation // @phpstan-ignore nullsafe.neverNull (a missing arm key is a real, reachable null: the corrupt-definition path an unknown arm test proves)
                ?? ($state->join?->arms[$record->arm] ?? null)?->compensation;
        }

        return $state->compensation;
    }

    /**
     * The event class whose delivery confirms this entry's effect: an arm entry is tracked by ITS
     * `wonBy` (race) or `completedBy` (join), an ordinary entry by the state's `confirmedBy`; null
     * means untracked, undone on positional progression only.
     *
     * @return class-string|null
     */
    private function trackedBy(ActivityState $state, CompensationRecord $record): ?string
    {
        if ($record->arm !== null) {
            // same equivalence family as undoFor() above: race/join exclusivity and PHP 8's
            // warn-then-null behavior on a dropped nullsafe leave no observable difference
            return ($state->race?->arms[$record->arm] ?? null)?->wonBy // @phpstan-ignore nullsafe.neverNull (a missing arm key is a real, reachable null: the corrupt-definition path an unknown arm test proves)
                ?? ($state->join?->arms[$record->arm] ?? null)?->completedBy;
        }

        return $state->compensationConfirmedBy;
    }
}
