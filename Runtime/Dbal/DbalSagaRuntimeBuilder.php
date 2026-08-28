<?php

declare(strict_types=1);

namespace Storm\Saga\Runtime\Dbal;

use Doctrine\DBAL\Connection;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Message\MessageContext;
use Storm\Message\ContextValues;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Calendar\BusinessCalendar;
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
use Storm\Saga\Locking\Dbal\PgAdvisoryFence;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\Dbal\DbalWorkflowTimerStore;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowTimerStore;
use Storm\Serializer\DefaultMessageSerializer;
use Storm\Serializer\MessageSerializer;

/**
 * Constructs the normal saga runtime graph over PostgreSQL, once, the same shape the container
 * wires: advisory fence, DBAL stores and outbox writer, the pure machine, the policy, and one
 * `StepExecutor` whose `JoinSettler` and `FamilyGate` carry the resolver-backed extraction built
 * from the given `EventResolver`. The container remains the production composition root; this
 * builder serves tests, standalone samples, and non-Symfony embedding, and it never substitutes a
 * weaker collaborator for a missing one: every semantic capability is either given or refused.
 *
 * Two capabilities are deliberately opt-in rather than defaulted, mirroring what only deployment
 * config can decide:
 *
 * - `calendar()`: the business calendar port stays unbound until given, the same refusal the
 *   container makes; the first business-time arm fails loud with `BusinessCalendarMissing`;
 * - `circuitBreaker()`: the container binds a DBAL-backed breaker from config; a runtime built
 *   here carries none unless given, since the breaker brings its own table and sweep cadence.
 *
 * The store setters override the DBAL defaults for decorating rigs, a spy over the timer store for
 * instance; the override must wrap the same connection or the one-step/one-transaction rule is no
 * longer proven by what the test drives.
 */
final class DbalSagaRuntimeBuilder
{
    private ?Connection $connection = null;

    private ?EventResolver $resolver = null;

    /** @var Clock<PointInTime>|null */
    private ?Clock $clock = null;

    private ?EventDispatcherInterface $events = null;

    private ?MessageSerializer $serializer = null;

    private ?MessageContext $context = null;

    private ?BusinessCalendar $calendar = null;

    private ?CircuitBreaker $breaker = null;

    private ?WorkflowInstanceStore $instances = null;

    private ?WorkflowTimerStore $timers = null;

    private function __construct(
        private readonly WorkflowRegistry $registry,
    ) {}

    public static function withRegistry(WorkflowRegistry $registry): self
    {
        return new self($registry);
    }

    public function connection(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function eventResolver(EventResolver $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * @param  Clock<PointInTime>  $clock
     */
    public function clock(Clock $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    public function events(EventDispatcherInterface $events): self
    {
        $this->events = $events;

        return $this;
    }

    /**
     * The wire codec for the sealed outbox rows; unset, the package's own `DefaultMessageSerializer`
     * is constructed bare, the standalone shape without app normalizers.
     */
    public function serializer(MessageSerializer $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * The ambient message context the `HopProtocol` seals into every command hop; unset, the hop
     * carries `ContextValues::empty()`, the shape of a runtime with no ambient delivery.
     */
    public function context(MessageContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function calendar(BusinessCalendar $calendar): self
    {
        $this->calendar = $calendar;

        return $this;
    }

    public function circuitBreaker(CircuitBreaker $breaker): self
    {
        $this->breaker = $breaker;

        return $this;
    }

    public function instances(WorkflowInstanceStore $instances): self
    {
        $this->instances = $instances;

        return $this;
    }

    public function timers(WorkflowTimerStore $timers): self
    {
        $this->timers = $timers;

        return $this;
    }

    /**
     * @throws LogicException when a required capability was not given: the connection, the event
     *                        resolver, the clock, or the dispatcher; a missing capability is
     *                        refused loud, never replaced by a weaker stand-in
     */
    public function build(): Engine
    {
        $connection = $this->connection ?? throw new LogicException('The saga runtime needs its DBAL connection: call connection() before build().');
        $resolver = $this->resolver ?? throw new LogicException('The saga runtime needs its event resolver: call eventResolver() before build().');
        $clock = $this->clock ?? throw new LogicException('The saga runtime needs its clock: call clock() before build().');
        $events = $this->events ?? throw new LogicException('The saga runtime needs its announcement dispatcher: call events() before build().');

        $selector = new TransitionSelector;
        $extraction = new WaitVarExtractor($resolver);
        $compensator = new Compensator($clock);
        $machine = new MachineRunner(
            new ActivityRunner($selector, $this->breaker),
            new WaitRunner($selector, $resolver, $extraction),
            new ScheduleRunner($selector),
            new FinalRunner,
            $compensator,
        );

        $instances = $this->instances ?? new DbalWorkflowInstanceStore($connection);
        $timers = $this->timers ?? new DbalWorkflowTimerStore($connection);
        $writer = new DbalWorkflowOutboxWriter($connection, $this->serializer ?? new DefaultMessageSerializer);
        $outbox = new WorkflowOutbox(new HopProtocol($this->context ?? ContextValues::empty()), $writer);

        $executor = new StepExecutor(
            new PgAdvisoryFence($connection),
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
            new StepCommitter($instances, $instances, $timers, $outbox, $clock, $this->calendar),
            $clock,
            $events,
        );

        return new Engine($this->registry, $executor, $instances, $writer);
    }
}
