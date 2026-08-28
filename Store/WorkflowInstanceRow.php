<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Clock\PointInTime;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Workflow\CompensationRecord;

/**
 * A `workflow_instances` row as the engine sees it: the identity, current `stateKey` and `status`, the
 * JSON `vars`, `retries`, and `context` bags, the OCC `version` an update must match, the `startedAt`
 * global-timeout anchor, and `compensations`, the rollback log. `compensations` is an ordered list of
 * `CompensationRecord` for the completed steps that declared a compensation, run in reverse on a
 * terminal failure, each entry carrying its confirmed flag and resolved status. `startedAt` is null
 * only for a not-yet-persisted row built without it; `find()` always hydrates it and `create()`
 * defaults it DB-side.
 *
 * `definitionVersion` is the pinned workflow version, set at birth by `fresh()` from the latest
 * registered version and IMMUTABLE thereafter, so every mover threads it untouched like `startedAt` and
 * the OCC `version`. The engine resolves the instance's definition by it. It is NOT the OCC `version`
 * but a different axis: one counts steps, the other names the definition shape.
 *
 * `generation` is the correlation's run counter, 1 for every saga under the default `reject` policy and
 * incrementing only where `#[Workflow(reuse: Allow)]` lets a business key run again. Like
 * `definitionVersion` it is set at birth and IMMUTABLE, threaded untouched by every mover, and it is
 * what seals an artifact to the run that wrote it: an outbox row carries it, so a dead-lettered command
 * of a past run cannot be paired against the living one.
 *
 * `stateVersion` is the THIRD axis: the shape of the data bags, stamped at birth from the definition's
 * declared `#[Workflow(stateVersion:)]`. Unlike the two anchors above it is not immutable forever: it
 * is the one axis a stored row may be migrated across while alive, by the explicit state migrator and
 * nobody else; every step mover threads it untouched. The engine refuses to run a row whose value
 * disagrees with the declaration, in either direction.
 *
 * `waivedAt` is the durable trace of a WAIVED global cap, stamped by `waived()` when the cap fell on a
 * retriable gating wait: set once and threaded untouched by every mover thereafter, an audit anchor
 * like `startedAt`. It keeps the waive honest under failure:
 *
 * - The cleanup's sweep finds the quiet saga even when the post-commit announcement was lost.
 *
 * - A straggler timer claimed before the waive reads it and stays quiet instead of resurrecting the
 *   heartbeat.
 *
 * - The policy knows the cap is spent when a later bounded gating wait is reached.
 *
 * @see CompensationRecord
 */
final readonly class WorkflowInstanceRow
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{n: int, since: string|null}>  $retries  per-state visit ledgers: attempt count
     *                                                                     plus the visit window's opening instant;
     *                                                                     a legacy integer entry hydrates with a
     *                                                                     null `since`, its clock starting at its
     *                                                                     next retry
     * @param  array<string, mixed>  $context
     * @param  list<CompensationRecord>  $compensations  the rollback log, in completion order
     * @param  array<string, list<string>>  $arms  per-join arrival ledgers: the arm names whose
     *                                             completion already landed, keyed by the ISSUING
     *                                             state; engine-owned, so the join's progress never
     *                                             leaks into the app's `vars`
     * @param  array<string, int>  $families  per-family EXPECTED member counts, keyed by the indexed
     *                                        `#[Spawns]` family: stamped by the engine in the same
     *                                        write as the fan-out that issued the spawns, so the
     *                                        family's completeness gate has an intention to count
     *                                        against; spawned and living members are read from the
     *                                        durable registry and the instances, never from here
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $stateKey,
        public WorkflowStatus $status,
        public array $vars = [],
        public array $retries = [],
        public array $context = [],
        public int $version = 0,
        public ?PointInTime $startedAt = null,
        public array $compensations = [],
        public int $definitionVersion = 1,
        public int $retryTotal = 0,
        public int $generation = 1,
        public ?PointInTime $waivedAt = null,
        public int $stateVersion = 1,
        public int $retimes = 0,
        public array $arms = [],
        public array $families = [],
        /**
         * The instance-level operator freeze, stamped and cleared by the pause verbs ONLY: no step
         * mover touches the pair, and the step's UPDATE does not write it, so a pause landing
         * mid-step cannot be clobbered. The status stays Running on purpose: a paused saga is a
         * LIVING one that is not executed.
         */
        public ?PointInTime $pausedAt = null,
        /** The pause verb's attributability, beside the stamp; null on an unpaused row. */
        public ?string $pausedReason = null,
        /**
         * The crossing a family gate rested and owes back, or null when nothing is owed. A gated
         * conclusion lands its vars and is then absorbed, the event itself going nowhere, so once
         * the family completes no stimulus capable of crossing exists any more. This is what the
         * gate keeps of it, and it is deliberately not the event: the crossing needs the CLASS and
         * nothing else, the match having been proved and the extract applied at the rest, so an
         * `#[On(onEvent:)]` edge routes and a `#[Compensate(confirmedBy:)]` record confirms from
         * the class alone. Nothing of the payload is stored.
         *
         * `state` seals it to the wait it was parked at, so a saga that moved on can only read a
         * stale park as stale; `cause` is the absorbed conclusion's own causation id, threaded onto
         * the replayed crossing so the trail names the business fact rather than the mechanism.
         *
         * @var array{state: string, event: class-string, cause: string|null}|null
         */
        public ?array $parked = null,
    ) {}

    /**
     * Whether this row settled by ABORTING, halted at a dead end or rolled back, as opposed to
     * completing its flow; `Completed` is what an app-level "failed" final state still is. The settle
     * reads this to recall the instance's still-pending outbox rows via
     * {@see WorkflowOutboxWriter::cancelPending()}: an aborting saga's undispatched commands are moot,
     * a completing saga's may be legitimate fire-and-forget.
     */
    public function aborted(): bool
    {
        return $this->status === WorkflowStatus::Halted || $this->status === WorkflowStatus::Compensated;
    }

    public function id(): WorkflowId
    {
        return new WorkflowId($this->workflowType, $this->correlationId);
    }

    /**
     * The declared parent of this instance, read from the birth context, or null for a root. The
     * context is immutable after birth, so parenthood cannot drift from what the schema projects
     * into the generated parent columns.
     *
     * @throws InvalidChildIdentity when a persisted parent declaration is malformed; corruption
     *                              surfaces loudly instead of reading as a root
     */
    public function parentRef(): ?ParentRef
    {
        return ParentRef::fromContext($this->context);
    }

    /**
     * A saga's birth row: the start state, Running, OCC version 0, an empty log, pinned to
     * `$definitionVersion`, the latest registered version of its workflow, resolved by the caller,
     * and stamped `$stateVersion`, the declared shape of its data bags.
     *
     * It takes no `generation`, deliberately: the run number does not exist until the claim has been
     * inserted, so the store hands it back and `claimedAs()` stamps it. A parameter here would
     * suggest a caller can decide it, which no caller can.
     *
     * @param  array<string, mixed>  $vars
     * @param  array<string, mixed>  $context
     */
    public static function fresh(WorkflowId $id, string $startState, array $vars, array $context, PointInTime $startedAt, int $definitionVersion, int $stateVersion = 1): self
    {
        return new self($id->workflowType, $id->correlationId, $startState, WorkflowStatus::Running, $vars, [], $context, 0, $startedAt, definitionVersion: $definitionVersion, stateVersion: $stateVersion);
    }

    /**
     * The run number the store handed back at birth, stamped on the in-memory row so the step's own
     * effects carry it. The ONE exception to "no mover touches an anchor": the anchor is not known until
     * the insert has run, and the row was built before it. Everything after is the usual threading.
     */
    public function claimedAs(int $generation): self
    {
        return clone ($this, ['generation' => $generation]);
    }

    /**
     * The machine came to rest: new state, status, and bags. `version`, `definitionVersion`,
     * `startedAt`, and `context` are untouchable by ANY mover; the OCC anchor, the pinned-version
     * anchor, the deadline anchor, and the audit context cannot fall victim to a positional mix-up.
     *
     * @param  array<string, mixed>  $vars
     * @param  array<string, array{n: int, since: string|null}>  $retries
     * @param  list<CompensationRecord>  $log
     * @param  int|null  $retryTotal  the new lifetime retry total; null carries forward, a retry passes +1
     */
    public function restingAt(string $stateKey, WorkflowStatus $status, array $vars, array $retries, array $log, ?int $retryTotal = null): self
    {
        return clone ($this, [
            'stateKey' => $stateKey,
            'status' => $status,
            'vars' => $vars,
            'retries' => $retries,
            'compensations' => $log,
            'retryTotal' => $retryTotal ?? $this->retryTotal,
            // a park belongs to the wait it was taken at: crossing anywhere else discharges it, so a
            // later visit to that same wait can never replay a previous visit's owed crossing
            'parked' => $stateKey === $this->stateKey ? $this->parked : null,
        ]);
    }

    /**
     * One join arm's completion landed: everything preserved, the arm joins its state's arrival
     * ledger. Idempotent by construction, the settler absorbing a redelivered outcome before calling
     * this, and monotonic: no mover ever removes an arrival, since the compensatable-cycle build
     * rule forbids revisiting a join state and its ledger dies with the row.
     */
    public function armArrived(string $stateKey, string $arm): self
    {
        $arms = $this->arms;
        $arms[$stateKey] = [...($arms[$stateKey] ?? []), $arm];

        return clone ($this, ['arms' => $arms]);
    }

    /**
     * A fan-out step spawned `$more` members of an indexed family: everything preserved, the
     * family's expected count grows in the SAME write as the step that issued the spawns. Monotonic
     * like the arms ledger: no mover ever lowers an expectation, and a later fan-out step may widen
     * the same family.
     */
    public function expectingFamily(string $family, int $more): self
    {
        $families = $this->families;
        $families[$family] = ($families[$family] ?? 0) + $more;

        return clone ($this, ['families' => $families]);
    }

    /**
     * A family gate rested a matched conclusion: everything preserved, the crossing it owes back
     * recorded against the wait it was taken at. Overwritten at every rest, deliberately, since any
     * one owed crossing is enough to cross and the last is the freshest.
     *
     * @param  class-string  $eventClass
     */
    public function parkedAt(string $eventClass, ?string $causationId): self
    {
        return clone ($this, ['parked' => ['state' => $this->stateKey, 'event' => $eventClass, 'cause' => $causationId]]);
    }

    /**
     * The owed crossing is being spent: everything preserved, the park discharged BEFORE the machine
     * runs, so a crossing that lands back on the same wait, a self-loop, cannot leave the poke a
     * second claim on it.
     */
    public function unparked(): self
    {
        return clone ($this, ['parked' => null]);
    }

    /**
     * A retime landed on the resting wait: everything preserved, the visit's retime counter bumps.
     * It is the counter `#[Retimable(maxRetimes:)]` caps: durable, so the budget survives restarts
     * and stays visible to inspection, and PER VISIT like the retry attempt cap. Any mover that
     * comes to rest on a new state resets it, since a crossing ends the old deadline's negotiation.
     * A recurring saga therefore renegotiates each window afresh instead of spending one lifetime
     * budget across all its cycles.
     */
    public function retimed(): self
    {
        return clone ($this, ['retimes' => $this->retimes + 1]);
    }

    /**
     * The deadline's negotiation ended: a crossing happened somewhere in the run, so the retime
     * budget resets for whatever spell comes next, the maxAttempts-per-visit philosophy, not
     * `retry_total`'s lifetime one. Applied by the CURSOR on any advanced rest, and by the forced
     * exit, because only they can see a crossing: a same-key compare in `restingAt` would miss a
     * leave-and-return within one run, which is a new visit all the same.
     */
    public function retimesReset(): self
    {
        return clone ($this, ['retimes' => 0]);
    }

    /**
     * Freeze on the spot: everything preserved, the status flips to Halted.
     */
    public function halted(): self
    {
        return clone ($this, ['status' => WorkflowStatus::Halted]);
    }

    /**
     * The global cap was WAIVED at this retriable, gating resting point: everything preserved, the
     * waive instant stamped. Set once and never cleared by any mover: the cap is spent for the
     * instance's whole remaining life, and the sweep and straggler guards read it as such.
     */
    public function waived(PointInTime $at): self
    {
        return clone ($this, ['waivedAt' => $at]);
    }

    /**
     * Force out of the current state via the global deadline's `onGlobalTimeout` routing; still Running.
     */
    public function forcedTo(string $stateKey): self
    {
        // a forced exit ends the deadline's negotiation like any crossing: the retime budget resets,
        // and an owed crossing dies with the wait it was owed at
        return clone ($this, ['stateKey' => $stateKey, 'status' => WorkflowStatus::Running, 'retimes' => 0, 'parked' => null]);
    }

    /**
     * The state migration landed: the bags in their new shape, the version that names it. THE one
     * sanctioned mover of `stateVersion`; every step mover threads it untouched, and only the
     * executor's migration chain calls this, under the fence, inside the step's transaction.
     *
     * @param  array<string, mixed>  $vars
     */
    public function migratedTo(int $stateVersion, array $vars): self
    {
        return clone ($this, ['stateVersion' => $stateVersion, 'vars' => $vars]);
    }

    /**
     * A compensation's verdict: the rollback's status, the vars, the undos threaded, the audited log.
     *
     * @param  array<string, mixed>  $vars
     * @param  list<CompensationRecord>  $log
     */
    public function settled(WorkflowStatus $status, array $vars, array $log): self
    {
        return clone ($this, ['status' => $status, 'vars' => $vars, 'compensations' => $log]);
    }
}
