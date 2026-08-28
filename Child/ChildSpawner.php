<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use JsonException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Engine\SagaStarter;
use Storm\Saga\Engine\StepExecutor;
use Storm\Saga\Event\SagaChildSpawnSkipped;
use Storm\Saga\Exception\ChildSpawnRefused;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\ParentNotAdoptable;
use Storm\Saga\Exception\ParentNotBornYet;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The framework side of a spawn: derives the lineage from the parent ROW, guards the birth, mints
 * the child identity, and starts through the consumer-safe port. The command names a slot; nothing
 * else about the child's identity is caller-controlled.
 *
 * The outcome is four-way, each with its own channel:
 *
 * - The parent is alive and the guards pass: the child is born, or was already born as a
 *   redelivery's quiet no-op at the port;
 *
 * - NO parent row exists: the start is still in flight, because the spawn reaches the broker from
 *   inside the parent handler's inbox transaction; the retryable {@see ParentNotBornYet} makes the
 *   transport redeliver until the start commits, or dead-letter a spawn whose start never did;
 *
 * - The parent row is terminal, or not the claimed workflow: the spawn is SKIPPED and announced
 *   as {@see SagaChildSpawnSkipped}, the normal outcome of the cancel-versus-spawn race, acked so
 *   it never manufactures dead-letter noise;
 *
 * - A quantitative guard trips, the child type is unknown, or the slot's correlation is owned by
 *   another type: the throw dead-letters the spawn and SETTLES the parent's leg, the same path any
 *   poisoned issued command takes.
 *
 * The alive-guard here is a lock-free PRE-CHECK, and deliberately not the proof. It is cheap and it
 * announces well, so it spares a doomed spawn the cost of opening a fence and gives the skip its
 * reason; but read without a lock, it cannot order itself against the parent's settle. The proof
 * that counts is taken inside the birth's own transaction, under a shared lock on the parent row
 * {@see StepExecutor}, and a birth refused there arrives back as {@see ParentNotAdoptable}, which
 * this class routes to the SAME announced skip: a parent that settled between the two reads is the
 * race resolving, not poison.
 *
 * Three windows are shut by design. "Decided, therefore the spawn exists" is closed by the parent's
 * own transaction. "The parent lives, therefore the child is adopted" is closed by that shared
 * lock: the settle must take the row exclusively, so either the birth commits first and the cascade
 * cancels the child, or the settle commits first and the birth refuses. And "the spawn arrived,
 * therefore the parent committed" is closed by {@see ParentNotBornYet}: the pre-check's OWN missing
 * row is the start-versus-spawn race, redelivered rather than skipped; an announced skip there
 * would lose the child forever while the outcome arm redelivers toward a birth that never comes.
 * The `storm:saga:children` sweep is the backstop for a cascade command that dies in flight, never
 * for a birth that raced.
 */
final readonly class ChildSpawner
{
    /**
     * Ceiling on rows per parent. Static slots are few by design: Temporal caps pending children
     * at 2000 for dynamic fan-out, and a static-slot parent near sixty-four is spawning in a loop.
     */
    public const int MAX_CHILDREN = 64;

    /**
     * Ceiling on the serialized spawn vars. The vars land in the child row and ride the outbox
     * payload; past this, pass a reference. Refused loudly here, not discovered in TOAST.
     */
    public const int MAX_VARS_BYTES = 65_536;

    public function __construct(
        private SagaStarter $engine,
        private WorkflowInstances $instances,
        private WorkflowFamilies $families,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @return bool true when the child was born by THIS call; false on the idempotent no-op of a
     *              redelivery, and on a skipped spawn
     *
     * @throws ParentNotBornYet when no parent row exists and the start is still in flight; the transport redelivers until it commits, or dead-letters the spawn as evidence of a start that never did
     * @throws ChildSpawnRefused when the depth, children, or vars ceiling trips, or the parent's definition never declared this slot for this child type; the consent proof is taken at the birth
     * @throws InvalidChildIdentity when the slot is not a valid static identifier, or the parent row carries a malformed parent declaration
     * @throws JsonException when the spawn vars cannot be measured as JSON
     * @throws CorrelationAlreadyOwned when the minted correlation is owned by another workflow type; a slot reused with a different child type
     * @throws SagaFenceBusy when a concurrent step holds the child's fence; retry the command
     * @throws SagaStorageFailure when the saga storage fails
     * @throws WorkflowNotFound when no workflow is registered under the child type
     */
    public function spawn(StartChildWorkflow $command, ?string $causationId = null): bool
    {
        $parent = $this->instances->findByCorrelation($command->parentCorrelationId);

        if ($parent === null) {
            // the start-versus-spawn race: the spawn reaches the broker from inside the parent
            // handler's inbox transaction, so a parallel worker can consume it before the parent
            // row commits. NOT the announced skip: acking here loses the child forever while the
            // outcome arm redelivers toward a birth that never comes; the retryable throw lets the
            // racing start win, and a start that never commits dead-letters the spawn as evidence
            throw ParentNotBornYet::forSpawn(
                $command->parentWorkflowType,
                $command->parentCorrelationId,
                $command->childWorkflowType,
                $command->slot,
            );
        }

        if ($parent->workflowType !== $command->parentWorkflowType) {
            return $this->skip($command, 'parent-mismatch');
        }

        if ($parent->status !== WorkflowStatus::Running) {
            return $this->skip($command, 'parent-terminal');
        }

        $ref = $this->lineage($parent, $command->slot);

        if ($ref->depth > ChildCorrelation::MAX_DEPTH) {
            throw ChildSpawnRefused::depthExceeded($command->childWorkflowType, $command->slot, $ref->depth, ChildCorrelation::MAX_DEPTH);
        }

        $children = $this->families->countChildren($command->parentCorrelationId);
        if ($children >= self::MAX_CHILDREN) {
            throw ChildSpawnRefused::tooManyChildren($command->parentCorrelationId, $children, self::MAX_CHILDREN);
        }

        $bytes = strlen(json_encode($command->vars, JSON_THROW_ON_ERROR));
        if ($bytes > self::MAX_VARS_BYTES) {
            throw ChildSpawnRefused::varsTooLarge($bytes, self::MAX_VARS_BYTES);
        }

        try {
            return $this->engine->startOrThrow(
                $command->childWorkflowType,
                $ref->childCorrelationId(),
                $command->vars,
                [ParentRef::CONTEXT_KEY => $ref->toContext()],
                $causationId,
            );
        } catch (ParentNotAdoptable $e) {
            // The pre-check above passed and the locked proof did not: the parent settled in between,
            // which IS the cancel-versus-spawn race resolving, so it takes the same announced-skip
            // channel rather than manufacturing dead-letter noise. The birth rolled back with its
            // step; nothing was created.
            return $this->skip($command, $e->reason);
        }
    }

    /**
     * The lineage is derived from the parent ROW, the authority the alive-guard fetched: a child of
     * a root starts a chain, a child of a child extends one; root propagated, depth incremented.
     *
     * @throws InvalidChildIdentity when the slot is invalid, or the parent row's own declaration is malformed
     */
    private function lineage(WorkflowInstanceRow $parent, string $slot): ParentRef
    {
        $parentRef = $parent->parentRef();

        if ($parentRef === null) {
            return new ParentRef($parent->workflowType, $parent->correlationId, $parent->correlationId, $slot, 1);
        }

        return new ParentRef($parent->workflowType, $parent->correlationId, $parentRef->rootCorrelationId, $slot, $parentRef->depth + 1);
    }

    private function skip(StartChildWorkflow $command, string $reason): bool
    {
        $this->events->dispatch(new SagaChildSpawnSkipped(
            $command->parentWorkflowType,
            $command->parentCorrelationId,
            // the parent's claimed run is not in hand on a skip path and may not even exist yet;
            // 0 is the honest unknown
            0,
            $command->childWorkflowType,
            $command->slot,
            $reason,
        ));

        return false;
    }
}
