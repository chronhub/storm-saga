<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaOutcomeNotYetApplicable;
use Storm\Saga\Outbox\FailedWorkflowCommands;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstances;

/**
 * The saga facade: each consumer-facing entry builds the `Signal` for what just happened, a start,
 * a delivered event, a fired timer, the global deadline, or a dead-lettered effect, and hands the
 * registry plus one fenced unit of work to the `StepExecutor`, which resolves the instance's pinned
 * definition once the row is loaded. All the loading, routing, driving, persisting, and
 * version-pinning lives behind it; the engine only maps a trigger to a `Signal`.
 *
 * The consumer contract, every method's intent and throws, lives on the port {@see SagaEngine};
 * this class carries only what is concrete to this implementation. Storm internals may type the
 * concrete; applications type the port.
 */
final readonly class Engine implements SagaEngine
{
    public function __construct(
        private WorkflowRegistry $registry,
        private StepExecutor $executor,
        private WorkflowInstances $instances,
        private FailedWorkflowCommands $outbox,
    ) {}

    public function start(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool
    {
        $this->guardChildIdentity($correlationId, $context);

        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::start($vars, $context, $causationId),
        )->applied();
    }

    public function startOrThrow(string $workflowType, string $correlationId, array $vars = [], array $context = [], ?string $causationId = null): bool
    {
        $this->guardChildIdentity($correlationId, $context);

        $report = $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::start($vars, $context, $causationId),
        );

        if ($report === ExecutionReport::FenceBusy) {
            throw SagaFenceBusy::whileStarting($workflowType, $correlationId);
        }

        // A start never yields NotYetApplicable: the signal is a birth, not an event ahead of a wait.
        // An already-started instance is a benign NothingToDo, returning false; a redelivery cannot help it.
        return $report->applied();
    }

    public function signal(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::user($signal, $causationId),
        )->applied();
    }

    public function signalFor(string $workflowType, string $correlationId, object $signal, ?string $causationId = null): ?object
    {
        $reply = $this->executor->executeSignalFor(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::user($signal, $causationId),
        );

        if ($reply->report === ExecutionReport::FenceBusy) {
            throw SagaFenceBusy::whileDelivering($workflowType, $correlationId);
        }

        return $reply->result;
    }

    public function startOrSignal(string $workflowType, string $correlationId, object $signal, array $vars = [], array $context = [], ?string $causationId = null): bool
    {
        $this->guardChildIdentity($correlationId, $context);

        $report = $this->executor->executeStartThenSignal(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::start($vars, $context, $causationId),
            Signal::user($signal, $causationId),
        );

        if ($report === ExecutionReport::FenceBusy) {
            throw SagaFenceBusy::whileStarting($workflowType, $correlationId);
        }

        return $report->applied();
    }

    public function deliver(string $workflowType, string $correlationId, object $event, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::event($event, $causationId),
        )->applied();
    }

    public function timeout(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::stateTimeout($expectedStateKey, $causationId),
        )->applied();
    }

    public function kick(string $workflowType, string $correlationId, string $expectedStateKey, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::kick($expectedStateKey, $causationId),
        )->applied();
    }

    public function schedule(string $workflowType, string $correlationId, string $expectedStateKey, PointInTime $dueAt, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::schedule($expectedStateKey, $dueAt, $causationId),
        )->applied();
    }

    public function globalTimeout(string $workflowType, string $correlationId, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::globalDeadline($causationId),
        )->applied();
    }

    public function cancel(string $workflowType, string $correlationId, ?string $reason = null, bool $force = false, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::cancel($reason, $force, $causationId),
        )->applied();
    }

    public function pokeFamily(string $workflowType, string $correlationId, ?string $causationId = null): bool
    {
        return $this->executor->execute(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
            Signal::familyPoke($causationId),
        )->applied();
    }

    public function deliverByCorrelation(string $correlationId, object $event, ?string $causationId = null): bool
    {
        $row = $this->instances->findByCorrelation($correlationId);
        if ($row === null) {
            return false; // no saga with this correlation; common when the event has a correlationId but isn't saga-bound
        }

        return $this->deliver($row->workflowType, $row->correlationId, $event, $causationId);
    }

    public function routeOutcome(string $correlationId, object $event, ?string $causationId = null): bool
    {
        $row = $this->instances->findByCorrelation($correlationId);
        if ($row === null) {
            return false; // no saga with this correlation; common, not an error
        }

        $report = $this->executor->execute(
            $this->registry,
            new WorkflowId($row->workflowType, $row->correlationId),
            Signal::event($event, $causationId),
        );

        if ($report === ExecutionReport::FenceBusy) {
            throw SagaFenceBusy::whileDelivering($row->workflowType, $row->correlationId);
        }

        if ($report === ExecutionReport::NotYetApplicable) {
            throw SagaOutcomeNotYetApplicable::whileDelivering($row->workflowType, $row->correlationId, $event::class);
        }

        // NeverApplicable deliberately does NOT throw: the definition proved no reachable wait accepts
        // this class, so asking the transport to redeliver would burn the budget and dead-letter a
        // routing fact as if it were an outage. The engine announced `SagaOutcomeDiscarded` instead.
        return $report->applied();
    }

    public function migrateState(string $workflowType, string $correlationId): bool
    {
        return $this->executor->migrateState(
            $this->registry,
            new WorkflowId($workflowType, $correlationId),
        )->applied();
    }

    public function failIssuedEffect(string $correlationId, ?string $causationId = null, ?string $failedMessageId = null): ExecutionReport
    {
        $row = $this->instances->findByCorrelation($correlationId);
        if ($row === null) {
            return ExecutionReport::NothingToDo;
        }

        // the pairing input, read pre-fence like the row resolution above: WHICH command died, issued by
        // which state, with living same-step siblings or not. Unknown, whether no id, unknown row, or
        // pre-upgrade rows, stays null; the policy treats unpaired as escalate-only, never as a settle.
        $provenance = $failedMessageId === null ? null : $this->outbox->provenance($correlationId, $failedMessageId, $row->generation);

        return $this->executor->execute(
            $this->registry,
            new WorkflowId($row->workflowType, $row->correlationId),
            Signal::effectFailure($causationId, $failedMessageId, $provenance),
        );
    }

    /**
     * The child-identity gate, on the two births only: a correlation id inside the reserved
     * namespace demands a declared parent, and a declared parent demands the exactly minted
     * correlation. Delivery, cancel and outcome routing stay ungated; an existing child is
     * addressed by its correlation without re-proving parenthood.
     *
     * Scope, because the division of labor matters: this gate proves IDENTITY COHERENCE only. It
     * reads no row, so it knows nothing of whether the declared parent exists or runs; ADOPTION is
     * proved later and lower, inside the birth's own transaction and under a shared lock on the
     * parent row {@see StepExecutor}, which is where it must live to be ordered against the parent's
     * settle. Cheap and lock-free here; authoritative there.
     *
     * Neither this gate nor its own reads prove CONSENT, namely that the parent DECLARED this slot
     * for this child type. That proof rides the same adoption seam: `proveAdoption` reads the
     * parent's pinned definition and refuses an undeclared slot as poison via `ChildSpawnRefused`,
     * never as a race. Identity here, existence and consent there: three proofs, one authoritative
     * home.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidChildIdentity when the correlation trespasses on the reserved namespace, the
     *                              declared parent is malformed, or the correlation is not the one
     *                              the declaration mints
     */
    private function guardChildIdentity(string $correlationId, array $context): void
    {
        $parent = ParentRef::fromContext($context);

        if ($parent === null) {
            if (ChildCorrelation::isChild($correlationId)) {
                throw InvalidChildIdentity::reservedNamespace($correlationId);
            }

            return;
        }

        $minted = $parent->childCorrelationId();

        if ($correlationId !== $minted) {
            throw InvalidChildIdentity::correlationMismatch($minted, $correlationId);
        }
    }
}
