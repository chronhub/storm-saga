<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Attributes\Spawns;
use Storm\Saga\Engine\Run\Rested;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Event\SagaFamilyMemberConcluded;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The completeness gate of an indexed spawn family: the family's `awaitedBy` wait consumes N
 * conclusions, and the saga must only cross it once every member is done. This is the join's
 * cross-at-last discipline, with the evidence in the DATABASE instead of an arrival ledger, because
 * a family's members are whole sagas whose lives the store already records.
 *
 * BEFORE the machine, a matched event on such a wait is judged against three counts: `expected`,
 * stamped on the row by the fan-out step's own write; `spawned`, the members ever claimed in the
 * durable correlation registry, which the terminal prune never thins; and `living`, the members
 * still running. Complete, meaning every expected member spawned and none still living, lets the
 * machine cross; anything less rests the saga in place, the event's vars landing exactly as the
 * crossing would land them, and announces the counts so a quiet family says WHY it waits. The
 * wait's standing timer is never touched: liveness is judged on the FAMILY, and a member whose
 * spawn dead-lettered surfaces through the wait's own heartbeat, the escalation naming what to
 * unpark.
 *
 * A wait that awaits NO stamped family, the static-slot common case or a family whose fan-out has
 * not run, gates nothing and costs nothing. Deduplication is not needed where the join needed it:
 * the counts are re-read from truth on every delivery, so a redelivered conclusion just reads the
 * same truth again.
 *
 * A rest is not free, and this is the second half of the gate. The event that rested is CONSUMED:
 * its vars land and it goes nowhere, the transport ACKing a fact the saga will never see again. When
 * the conclusion that rests is the LAST one to arrive, the family completing only afterwards, no
 * stimulus capable of crossing exists any more and the wait would sit forever. So every rest parks
 * what the crossing needs of that event, the class alone, and the terminal settle of any member
 * pokes the parent to spend it. Parking happens at EVERY rest and not only at the endgame: which
 * conclusion turns out to be the last is not knowable when it lands, and the counts a rest reads
 * cannot tell a conclusion that doubles its own member's settle from the one before it.
 *
 * @see Spawns
 * @see JoinSettler the command-level twin, whose evidence lives in the arms ledger
 */
final readonly class FamilyGate
{
    public function __construct(
        private WorkflowFamilies $instances,
        private WaitVarExtractor $extraction,
    ) {}

    /**
     * Rest a matched conclusion in place while the resting wait's families are incomplete, parking
     * the crossing it owes back; null hands the stimulus to the machine: no stamped family awaited
     * here, an event the wait refuses, or every family complete.
     *
     * @throws SagaStorageFailure when the member counts cannot be read; the step rolls back whole
     * @throws MissingExtractField when the wait's extract map names a field the conclusion's payload does not carry
     */
    public function gateConclusion(WorkflowDefinition $def, WorkflowInstanceRow $row, Stimulus $stimulus, ?string $causationId): ?Rested
    {
        $event = $stimulus->eventOrNull();
        if ($event === null) {
            return null;
        }

        $awaited = $this->awaitedFamilies($def, $row);
        if ($awaited === []) {
            return null;
        }

        $wait = $def->state($row->stateKey);
        if (! $wait instanceof WaitState || ! $this->extraction->matches($wait, $event, $row->vars)) {
            return null; // an event the wait refuses: the machine will Noop identically
        }

        $announcements = $this->judge($row, $awaited);
        if ($announcements === []) {
            return null; // every family complete: the machine crosses
        }

        return new Rested(
            $row->restingAt(
                $row->stateKey,
                $row->status,
                $this->extraction->apply($wait, $event, $row->vars),
                $row->retries,
                $row->compensations,
            )->parkedAt($event::class, $causationId),
            $announcements,
        );
    }

    /**
     * Whether every family the resting wait awaits is now complete, the poke's own question, asked
     * of the same counts a conclusion is judged against and answered from truth in the database. A
     * wait awaiting no stamped family answers true: it never gated, so it owes nothing, and the
     * caller's park check is what decides there is anything to spend.
     *
     * @throws SagaStorageFailure when the member counts cannot be read; the step rolls back whole
     */
    public function readyToCross(WorkflowDefinition $def, WorkflowInstanceRow $row): bool
    {
        return $this->judge($row, $this->awaitedFamilies($def, $row)) === [];
    }

    /**
     * The indexed families this row's resting wait awaits AND has fanned out, in declaration order.
     * All three conditions are load-bearing and none implies another: a family declared elsewhere in
     * the graph is not this wait's business, a static slot never gates whatever it awaits, and a
     * declared family whose fan-out has not run has no expectation to count against.
     *
     * @return list<string>
     */
    private function awaitedFamilies(WorkflowDefinition $def, WorkflowInstanceRow $row): array
    {
        $awaited = [];
        foreach ($def->spawns as $slot) {
            if ($slot->indexed && $slot->awaitedBy === $row->stateKey && isset($row->families[$slot->slot])) {
                $awaited[] = $slot->slot;
            }
        }

        return $awaited;
    }

    /**
     * One announcement per family still holding the gate, empty when every one of them is complete.
     * The announcement IS the judgement: a family that says why it waits and a family that lets the
     * saga through cannot be told apart by a count taken elsewhere.
     *
     * @param  list<string>  $awaited
     * @return list<SagaFamilyMemberConcluded>
     *
     * @throws SagaStorageFailure when the member counts cannot be read; the step rolls back whole
     */
    private function judge(WorkflowInstanceRow $row, array $awaited): array
    {
        $announcements = [];
        foreach ($awaited as $family) {
            $expected = $row->families[$family];
            $spawned = $this->instances->spawnedMembers($row->correlationId, $family);
            $living = $this->instances->livingMembers($row->correlationId, $family);
            if ($spawned >= $expected && $living === 0) {
                continue; // this family is done; another may still hold the gate
            }
            $announcements[] = new SagaFamilyMemberConcluded(
                $row->workflowType,
                $row->correlationId,
                $row->generation,
                $row->stateKey,
                $family,
                $expected,
                $spawned,
                $living,
            );
        }

        return $announcements;
    }
}
