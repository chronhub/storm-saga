<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Exception\SagaStorageFailure;

/**
 * The child-topology capability of the saga store: counts and reads over a parent's children and
 * its indexed spawn families, plus the adoption read that orders a child's birth against its
 * parent's settle. The spawner, the family gate, and the step's commit invariants ask for this
 * capability. Every method may throw the port-owned `SagaStorageFailure` with the driver's failure
 * wrapped.
 *
 * @see SagaStorageFailure
 */
interface WorkflowFamilies
{
    /**
     * How many children a parent has, ANY status: the ceiling guard of the spawner. Terminal
     * children count: with static slots the total is a hard bound on distinct slots, which is the
     * runaway signal the guard watches; a same-slot respawn re-mints the same correlation and never
     * adds a row.
     */
    public function countChildren(string $parentCorrelationId): int;

    /**
     * `countChildren()` taken as the AUTHORITY: concurrent births of one parent are serialized
     * first, so two racing spawns cannot both read the same count under the ceiling, closing the
     * check-then-act the spawner's lock-free pre-check leaves open. The DBAL adapter serializes on
     * an advisory transaction lock over the parent's spawn lane; a sequential adapter is serial by
     * construction. Called inside the birth's own fenced transaction only; the ordering dies with it.
     */
    public function countChildrenSerialized(string $parentCorrelationId): int;

    /**
     * The living children of a parent, `running` only: the cascade's targets and the nominal
     * settle-guard's evidence. Rides the partial children index; static slots keep the result
     * small by design.
     *
     * @return list<WorkflowInstanceRow>
     */
    public function livingChildren(string $parentCorrelationId): array;

    /**
     * How many members of an indexed spawn family were EVER claimed, read from the durable
     * correlation registry: member identities are deterministic, `parent` then the delimiter then
     * `family-<i>`, and the registry is retention-permanent, so this count survives the terminal
     * prune that makes the instances table lie about concluded members.
     */
    public function spawnedMembers(string $parentCorrelationId, string $family): int;

    /**
     * How many members of an indexed spawn family still RUN: the family completeness gate's other
     * half, the gate crossing only when every expected member was spawned and none still lives.
     */
    public function livingMembers(string $parentCorrelationId, string $family): int;

    /**
     * The adoption proof of a child's birth: read the would-be parent while ORDERING the birth
     * against the parent's settle, which must take the row exclusively to move the status.
     * Whichever wins, the outcome is sound: the birth lands first, so the settle's cascade sees the
     * child and cancels it; or the settle lands first, so the birth reads a terminal parent and
     * refuses. Without the ordering the two overlap and an orphan is possible, the window this
     * method exists to close.
     *
     * The DBAL adapter holds a SHARED row lock until the calling transaction settles, shared so
     * sibling slots of one parent are born concurrently and only serialize against the settle; a
     * sequential adapter satisfies the ordering by construction. Called inside the birth's own
     * fenced transaction only. Returns null when no saga holds the correlation.
     */
    public function loadAdoptableParent(string $correlationId): ?WorkflowInstanceRow;
}
