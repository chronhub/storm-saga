<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use JsonException;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Message\ContextValues;
use Storm\Message\Header;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\BusinessCalendar;
use Storm\Saga\Child\CancelChildWorkflow;
use Storm\Saga\Child\ChildCanceller;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Child\FamilyPoker;
use Storm\Saga\Child\PokeParentFamily;
use Storm\Saga\Child\StartChildWorkflow;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\Engine\Canceller;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\DeadlineEnforcer;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Engine\FailedEffectSettler;
use Storm\Saga\Engine\FamilyGate;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Engine\MachineRunner;
use Storm\Saga\Engine\RaceSettler;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Engine\StepExecutor;
use Storm\Saga\Engine\StepLoader;
use Storm\Saga\Engine\StepPerformer;
use Storm\Saga\Engine\StepPolicy;
use Storm\Saga\Engine\WaitEscalator;
use Storm\Saga\Exception\ChildSpawnRefused;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\InvalidChildIdentity;
use Storm\Saga\Exception\ParentNotBornYet;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowTimerRow;
use Storm\Saga\Workflow\WorkflowDefinition;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;

/**
 * The deterministic in-memory saga runtime: the REAL definition builder, policy, machine,
 * compensation logic and step executor over array-backed adapters, a controlled clock and a
 * capturing outbox. A functional simulator of ONE process for package tests, application workflow
 * tests and executable samples; never an alternate durable runtime, never registered by production
 * configuration, constructed explicitly from testing or sample code only.
 *
 * Time is two operations on purpose: `advanceBySeconds()` moves the clock and runs nothing, while
 * `runDueTimers()` drives the real `TimerRunner` over the due rows, so a test can observe "due but
 * not processed", model worker downtime, and exercise catch-up. Commands are captured sealed and
 * NEVER auto-executed: delivering an outcome stays the test's explicit move, since erasing that
 * consistency boundary is exactly what a saga exists to teach. The framework's child protocol
 * obeys the same rule: `spawnChild()` and `cancelChild()` hand a published command to the real
 * spawner and canceller, the bus delivery as an explicit move.
 *
 * What each semantic tier proves, and what stays PostgreSQL's:
 *
 * | Capability                     | In-memory runtime                  | PostgreSQL integration      |
 * |--------------------------------|------------------------------------|-----------------------------|
 * | definition validation          | authoritative, same builder        | same builder                |
 * | pure state transitions         | authoritative, same machine        | same machine                |
 * | retries, compensation, timers  | deterministic sequential model     | durable, concurrent model   |
 * | step rollback                  | snapshot and restore               | database transaction        |
 * | correlation reuse, generation  | sequential model                   | schema and transaction      |
 * | outbox provenance and status   | sequential model                   | durable rows, relay races   |
 * | child and family rules         | sequential model                   | row and advisory ordering   |
 * | fence and OCC contention       | reentrant simulation only          | authoritative               |
 * | claim leasing, SKIP LOCKED     | sequential approximation           | authoritative               |
 * | crash recovery                 | not supported                      | authoritative               |
 * | schema, SQL, JSONB             | not supported                      | authoritative               |
 *
 * The limitation is a feature: a test picks the cheapest tier that proves the behavior it cares
 * about, and the PostgreSQL suite remains mandatory for every guarantee in its column.
 */
final readonly class InMemorySagaRuntime
{
    private function __construct(
        private ControlledClock $clock,
        private InMemorySagaState $state,
        private Engine $engine,
        private InMemoryWorkflowInstances $instances,
        private InMemoryWorkflowTimers $timers,
        private InMemoryWorkflowCommands $commands,
        private RecordingSagaEvents $events,
        private TimerRunner $runner,
        private ChildSpawner $spawner,
        private ChildCanceller $canceller,
        private FamilyPoker $poker,
        private MessageSerializer $serializer,
    ) {}

    /**
     * A runtime over ATTRIBUTE workflows, built by the real `WorkflowBuilder`, so a sample verifies
     * attribute validity and activity resolution exactly as the container's discovery would.
     *
     * @param  list<object>  $workflows  `#[Workflow]`-attributed instances
     * @param  list<object>  $activities  the activity instances the workflows' states name by class
     * @param  PointInTime|null  $now  the clock's starting instant; unset, a fixed epoch, so two
     *                                 runs of one scenario read the same instants
     * @param  EventResolver|null  $resolver  unset, the alias-less `PlainEventResolver`; a
     *                                        deployment whose waits match by alias hands its real one
     */
    public static function fromWorkflows(array $workflows, array $activities = [], ?PointInTime $now = null, ?EventResolver $resolver = null, ?BusinessCalendar $calendar = null): self
    {
        return self::fromRegistry(
            WorkflowRegistry::fromWorkflows($workflows, new WorkflowBuilder(new ActivityLocator($activities))),
            $now,
            $resolver,
            $calendar,
        );
    }

    /**
     * A runtime over already-built definitions, the engine-package tests' door.
     *
     * @param  list<WorkflowDefinition>  $definitions
     */
    public static function fromDefinitions(array $definitions, ?PointInTime $now = null, ?EventResolver $resolver = null, ?BusinessCalendar $calendar = null): self
    {
        return self::fromRegistry(new WorkflowRegistry($definitions), $now, $resolver, $calendar);
    }

    private static function fromRegistry(WorkflowRegistry $registry, ?PointInTime $now, ?EventResolver $resolver, ?BusinessCalendar $calendar): self
    {
        $clock = new ControlledClock($now ?? PointInTime::from('2026-01-01T00:00:00.000000+00:00'));
        $state = new InMemorySagaState;
        $serializer = new DefaultMessageSerializer;
        $resolver ??= new PlainEventResolver;

        $instances = new InMemoryWorkflowInstances($state, $clock);
        $timers = new InMemoryWorkflowTimers($state, $clock);
        $commands = new InMemoryWorkflowCommands($state, $serializer, $clock);
        $events = new RecordingSagaEvents;

        $selector = new TransitionSelector;
        $extraction = new WaitVarExtractor($resolver);
        $compensator = new Compensator($clock);
        $machine = new MachineRunner(
            new ActivityRunner($selector, new CircuitBreaker(new InMemoryCircuitBreakers($state, $clock), $clock)),
            new WaitRunner($selector, $resolver, $extraction),
            new ScheduleRunner($selector),
            new FinalRunner,
            $compensator,
        );
        $outbox = new WorkflowOutbox(new HopProtocol(ContextValues::empty(), new DeterministicIdentity($state)), $commands);

        $executor = new StepExecutor(
            new InMemoryStepUnitOfWork($state),
            new StepLoader($instances, $instances, $timers),
            new StepPolicy,
            new StepPerformer(
                $machine,
                $compensator,
                new WaitEscalator,
                new DeadlineEnforcer($machine, $compensator),
                new FailedEffectSettler($compensator),
                new Canceller($compensator),
                new JoinSettler($outbox, $clock, $extraction),
                new RaceSettler($outbox, $clock),
                new FamilyGate($instances, $extraction),
            ),
            new StepCommitter($instances, $instances, $timers, $outbox, $clock, $calendar),
            $clock,
            $events,
        );

        $engine = new Engine($registry, $executor, $instances, $commands);

        // the real child handlers over the same engine, stores and dispatcher the wired deployment
        // gives them, so the sequential model drives the true guards, lineage and birth
        $spawner = new ChildSpawner($engine, $instances, $instances, $events);
        $canceller = new ChildCanceller($engine, $instances, $events);
        $poker = new FamilyPoker($engine);

        return new self($clock, $state, $engine, $instances, $timers, $commands, $events, new TimerRunner($timers, $engine, $clock), $spawner, $canceller, $poker, $serializer);
    }

    /** The real engine facade; starts, deliveries, signals and operator verbs go through it. */
    public function engine(): Engine
    {
        return $this->engine;
    }

    /**
     * Move the clock; nothing runs, due timers wait for an explicit `runDueTimers()`.
     *
     * @param  positive-int  $seconds
     */
    public function advanceBySeconds(int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }

    public function now(): PointInTime
    {
        return $this->clock->now();
    }

    /**
     * Drive the real `TimerRunner` once over the due rows: claims, leases, attempts, parking and
     * the pause gates all follow the port contract in sequential form.
     *
     * @param  positive-int  $batch
     * @return int how many due timers were driven
     */
    public function runDueTimers(int $batch = 100): int
    {
        return $this->runner->tick($batch)->processed;
    }

    public function instance(string $workflowType, string $correlationId): ?WorkflowInstanceRow
    {
        return $this->instances->find(new WorkflowId($workflowType, $correlationId));
    }

    /**
     * @return list<WorkflowTimerRow>
     */
    public function timersFor(string $workflowType, string $correlationId): array
    {
        return $this->timers->listFor(new WorkflowId($workflowType, $correlationId));
    }

    /**
     * The captured commands, every status, in issue order; `$payloadClass` narrows to one command
     * class.
     *
     * @param  class-string|null  $payloadClass
     * @return list<CapturedCommand>
     */
    public function commands(?OutboxStatus $status = null, ?string $payloadClass = null): array
    {
        $captured = [];
        foreach ($this->state->commands as $row) {
            if ($status instanceof OutboxStatus && $row['status'] !== $status->value) {
                continue;
            }
            $command = $this->capture($row);
            if ($payloadClass !== null && ! $command->payload() instanceof $payloadClass) {
                continue;
            }
            $captured[] = $command;
        }

        return $captured;
    }

    /**
     * The commands still pending on the outbox: issued, sealed, not yet published; what the relay
     * would drain in the wired deployment.
     *
     * @param  class-string|null  $payloadClass
     * @return list<CapturedCommand>
     */
    public function pendingCommands(?string $payloadClass = null): array
    {
        return $this->commands(OutboxStatus::Pending, $payloadClass);
    }

    /**
     * The FIRST pending command wrapping `$payloadClass`, refused loud when none is: a scenario
     * asking for a command the saga never issued is a scenario bug, never a null to check.
     *
     * @param  class-string  $payloadClass
     *
     * @throws RuntimeException when no pending command wraps `$payloadClass`
     */
    public function takeCommand(string $payloadClass): CapturedCommand
    {
        return $this->pendingCommands($payloadClass)[0]
            ?? throw new RuntimeException(sprintf('No pending command wraps "%s".', $payloadClass));
    }

    /**
     * The test's stand-in for the relay's mark: flip one pending command, by its sealed message id,
     * to published. Refused loud for an unknown id or a command no longer pending.
     *
     * @throws RuntimeException when no pending command carries `$messageId`
     */
    public function markPublished(string $messageId): void
    {
        if (! $this->commands->markPublished($messageId)) {
            throw new RuntimeException(sprintf('No pending command carries the message id "%s".', $messageId));
        }
    }

    /**
     * Hands a published `StartChildWorkflow` to the real spawner, the bus delivery as the test's
     * explicit move: the same consent proof, ceilings, minted correlation and birth as the wired
     * deployment.
     *
     * @return bool true when the child was born by this call; false on the idempotent no-op of a
     *              redelivery, and on a skipped spawn
     *
     * @throws RuntimeException when the command does not wrap a `StartChildWorkflow`, or was never
     *                          marked published; delivery follows the relay's mark, and a scenario
     *                          skipping it drives an order the wired deployment never runs
     * @throws ParentNotBornYet when no parent row exists; the wired transport would redeliver
     * @throws ChildSpawnRefused when a depth, children or vars ceiling trips, or the parent's definition never declared this slot for this child type
     * @throws CorrelationAlreadyOwned when the minted correlation is owned by another workflow type
     * @throws InvalidChildIdentity when the slot is not a valid static identifier, or the parent row carries a malformed parent declaration
     * @throws JsonException when the spawn vars cannot be measured as JSON
     * @throws SagaFenceBusy when a concurrent step holds the child's fence
     * @throws SagaStorageFailure when the saga storage fails
     * @throws WorkflowNotFound when no workflow is registered under the child type
     */
    public function spawnChild(CapturedCommand $command): bool
    {
        $payload = $command->payload();
        if (! $payload instanceof StartChildWorkflow) {
            throw new RuntimeException(sprintf('spawnChild() delivers a %s; the captured command wraps "%s".', StartChildWorkflow::class, $payload::class));
        }
        $this->assertPublished($command, 'spawnChild');

        return $this->spawner->spawn($payload);
    }

    /**
     * Hands a published `CancelChildWorkflow` to the real canceller, the cascade's bus delivery as
     * the test's explicit move.
     *
     * @return bool true when the engine applied a cancel; false on the quiet no-ops and the
     *              announced skips
     *
     * @throws RuntimeException when the command does not wrap a `CancelChildWorkflow`, or was never marked published
     * @throws InvalidChildIdentity when the child row carries a malformed parent declaration
     * @throws SagaFenceBusy when a concurrent step holds the child's fence
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function cancelChild(CapturedCommand $command): bool
    {
        $payload = $command->payload();
        if (! $payload instanceof CancelChildWorkflow) {
            throw new RuntimeException(sprintf('cancelChild() delivers a %s; the captured command wraps "%s".', CancelChildWorkflow::class, $payload::class));
        }
        $this->assertPublished($command, 'cancelChild');

        return $this->canceller->cancel($payload);
    }

    /**
     * Hands a published `PokeParentFamily` to the real poker, the family endgame's bus delivery as
     * the test's explicit move. Every member's terminal settle writes one, so most calls answer
     * false; the one worth asserting is the poke of the member that completes the family, which is
     * what lets a parent spend a conclusion its gate absorbed while that member still ran.
     *
     * @return bool true when the parent spent a parked crossing; false when it owed none, had moved
     *              on from the wait it parked at, or no longer exists
     *
     * @throws RuntimeException when the command does not wrap a `PokeParentFamily`, or was never marked published
     * @throws InvalidChildIdentity when the command's child is not a family member of the parent it names
     * @throws SagaFenceBusy when a concurrent step holds the parent's fence
     * @throws SagaStorageFailure when the saga storage fails
     */
    public function pokeParent(CapturedCommand $command): bool
    {
        $payload = $command->payload();
        if (! $payload instanceof PokeParentFamily) {
            throw new RuntimeException(sprintf('pokeParent() delivers a %s; the captured command wraps "%s".', PokeParentFamily::class, $payload::class));
        }
        $this->assertPublished($command, 'pokeParent');

        return $this->poker->poke($payload);
    }

    /**
     * The LIVING children of a parent, the cascade's view.
     *
     * @return list<WorkflowInstanceRow>
     */
    public function childrenOf(string $parentCorrelationId): array
    {
        return $this->instances->livingChildren($parentCorrelationId);
    }

    private function assertPublished(CapturedCommand $command, string $verb): void
    {
        if (array_any($this->commands(OutboxStatus::Published), fn ($published) => $published->messageId === $command->messageId)) {
            return;
        }

        throw new RuntimeException(sprintf('%s() delivers only a published command; mark "%s" published first.', $verb, $command->messageId));
    }

    /**
     * The committed announcements, in dispatch order; a rolled-back step announced nothing.
     *
     * @return list<object>
     */
    public function announcements(): array
    {
        return $this->events->announcements();
    }

    public function resetAnnouncements(): void
    {
        $this->events->reset();
    }

    /** Drop the published command rows, so a scenario's next phase inspects only its own issues. */
    public function resetPublishedCommands(): void
    {
        foreach ($this->state->commands as $id => $row) {
            if ($row['status'] === OutboxStatus::Published->value) {
                unset($this->state->commands[$id]);
            }
        }
    }

    /**
     * @param  array{header: array<string, mixed>, content: array<string, mixed>, workflowType: string, correlationId: string, status: string}  $row
     */
    private function capture(array $row): CapturedCommand
    {
        $message = $this->serializer->deserialize(['header' => $row['header'], 'content' => $row['content']]);

        return new CapturedCommand(
            (string) $message->header(Header::MessageId),
            $row['workflowType'],
            $row['correlationId'],
            OutboxStatus::from($row['status']),
            $message->message(),
        );
    }
}
