<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\CircuitBreaker\CircuitBreaker;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\Dbal\DbalCircuitBreakerStorage;
use Storm\Saga\CircuitBreaker\InMemory\InMemoryCircuitBreakerStorage;
use Storm\Saga\Console\InspectSagaCommand;
use Storm\Saga\Console\InstallSagaCommand;
use Storm\Saga\Console\ListSagasCommand;
use Storm\Saga\Console\RelaySagaOutboxCommand;
use Storm\Saga\Console\RunTimersCommand;
use Storm\Saga\Console\SagaCancelCommand;
use Storm\Saga\Console\SagaChildrenCommand;
use Storm\Saga\Console\SagaCleanupCommand;
use Storm\Saga\Console\SagaRedriveCommand;
use Storm\Saga\Console\SagaStateMigrateCommand;
use Storm\Saga\Console\SagaPauseCommand;
use Storm\Saga\Console\SagaResumeCommand;
use Storm\Saga\Console\SagaUnparkCommand;
use Storm\Saga\Console\SagaVersionsCommand;
use Storm\Saga\Engine\Canceller;
use Storm\Saga\Engine\Compensator;
use Storm\Saga\Engine\DeadlineEnforcer;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Engine\SagaFamilyTarget;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Engine\SagaOutcomeDelivery;
use Storm\Saga\Engine\SagaSignaller;
use Storm\Saga\Engine\SagaStarter;
use Storm\Saga\Engine\SagaTimerTarget;
use Storm\Saga\Engine\FailedEffectSettler;
use Storm\Saga\Engine\MachineRunner;
use Storm\Saga\Engine\FamilyGate;
use Storm\Saga\Engine\JoinSettler;
use Storm\Saga\Engine\State\ActivityRunner;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\State\ScheduleRunner;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Engine\State\WaitRunner;
use Storm\Saga\Engine\State\WaitVarExtractor;
use Storm\Saga\Engine\RaceSettler;
use Storm\Saga\Engine\StepCommitter;
use Storm\Saga\Engine\StepExecutor;
use Storm\Saga\Engine\StepLoader;
use Storm\Saga\Engine\StepPerformer;
use Storm\Saga\Engine\StepPolicy;
use Storm\Saga\Engine\WaitEscalator;
use Storm\Saga\Locking\SagaStepUnitOfWork;
use Storm\Saga\Locking\Dbal\PgAdvisoryFence;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Saga\Outbox\WorkflowOutbox;
use Storm\Saga\Outbox\FailedWorkflowCommands;
use Storm\Saga\Outbox\WorkflowCommandStore;
use Storm\Saga\Outbox\WorkflowOutboxWriter;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Saga\Child\ChildCanceller;
use Storm\Saga\Child\FamilyPoker;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Semaphore\SemaphoreClient;
use Storm\Saga\Semaphore\SemaphoreWorkflow;
use Storm\Saga\Semaphore\SweepActivity;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\DueTimerQueue;
use Storm\Saga\Store\Dbal\DbalWorkflowTimerStore;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\SagaMaintenanceReader;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowInstanceStore;
use Storm\Saga\Store\WorkflowPauses;
use Storm\Saga\Store\WorkflowTimers;
use Storm\Saga\Store\WorkflowTimerStore;

/*
 * Saga package wiring.
 *
 * Discovery: the WorkflowBuilder resolves each activity from a service-locator of
 * #[Activity]-tagged services; the WorkflowRegistry is built from the #[Workflow]-tagged instances,
 * which the bundle tags via attribute autoconfiguration, each reflected into a definition.
 *
 * Engine: the runtime graph, the pure state runners, the DBAL stores / advisory
 * fence / outbox writer, the StepExecutor with one fenced transaction, and the Engine facade. The EventResolver
 * port is bound by the bundle to MappedEventResolver, which bridges Chronicler's event-type mapper, which
 * Saga must not depend on. The TimerRunner / SagaOutboxRelay and their consoles, the storm.saga.*
 * config, and the reaction path SagaOutcomeRouter round out the saga wiring.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire();

    // Discovery
    $services->set(WorkflowBuilder::class)
        ->args([tagged_locator('storm.saga.activity')]);

    $services->set(WorkflowRegistry::class)
        ->factory([WorkflowRegistry::class, 'fromWorkflows'])
        ->args([tagged_iterator('storm.saga.workflow'), service(WorkflowBuilder::class)]);

    // Engine: pure core plus facade; EventResolver is bound by the bundle
    $services->set(TransitionSelector::class);
    $services->set(ActivityRunner::class);
    $services->set(WaitVarExtractor::class);
    $services->set(WaitRunner::class);
    $services->set(JoinSettler::class);
    $services->set(FamilyGate::class);
    $services->set(ScheduleRunner::class);
    $services->set(FinalRunner::class);
    $services->set(Compensator::class);
    $services->set(StepPolicy::class);
    $services->set(MachineRunner::class);
    $services->set(WaitEscalator::class);
    $services->set(DeadlineEnforcer::class);
    $services->set(FailedEffectSettler::class);
    $services->set(Canceller::class);
    $services->set(RaceSettler::class);
    $services->set(StepLoader::class);
    $services->set(StepPerformer::class);
    $services->set(StepCommitter::class);
    $services->set(StepExecutor::class);
    $services->set(Engine::class);
    // the engine PORT and its five roles, every alias to the same engine: a consumer type-hints
    // the role it uses, the full facade stays for one that genuinely spans the surface
    $services->alias(SagaEngine::class, Engine::class);
    $services->alias(SagaStarter::class, Engine::class);
    $services->alias(SagaSignaller::class, Engine::class);
    $services->alias(SagaOutcomeDelivery::class, Engine::class);
    $services->alias(SagaTimerTarget::class, Engine::class);
    $services->alias(SagaOperator::class, Engine::class);
    $services->alias(SagaFamilyTarget::class, Engine::class);

    // the child-workflow spawner, canceller and family poker: pure guards, lineage and proof of
    // parenthood; the Messenger handlers live in the bundle
    $services->set(ChildSpawner::class);
    $services->set(ChildCanceller::class);
    $services->set(FamilyPoker::class);

    // The shipped durable semaphore: a generic workflow tagged like any app workflow, its sweep
    // activity in the same locator, and the consumer facade. The GrantSlot wake-up handler lives in
    // the bundle, keeping Messenger out of the package; the tags are explicit because this file's
    // defaults do not autoconfigure.
    $services->set(SemaphoreWorkflow::class)->tag('storm.saga.workflow');
    $services->set(SweepActivity::class)->tag('storm.saga.activity');
    $services->set(SemaphoreClient::class);

    // Persistence / locking ports map to DBAL and advisory impls
    $services->set(DbalWorkflowInstanceStore::class);
    $services->alias(WorkflowInstanceStore::class, DbalWorkflowInstanceStore::class);
    $services->alias(WorkflowInstances::class, DbalWorkflowInstanceStore::class);
    $services->alias(WorkflowFamilies::class, DbalWorkflowInstanceStore::class);
    $services->alias(WorkflowPauses::class, DbalWorkflowInstanceStore::class);
    $services->alias(SagaMaintenanceReader::class, DbalWorkflowInstanceStore::class);

    $services->set(DbalWorkflowTimerStore::class);
    $services->alias(WorkflowTimerStore::class, DbalWorkflowTimerStore::class);
    $services->alias(WorkflowTimers::class, DbalWorkflowTimerStore::class);
    $services->alias(DueTimerQueue::class, DbalWorkflowTimerStore::class);

    // Forensic read-gateway behind storm:saga:inspect, storm:saga:list and storm:saga:children: owns the
    // cross-table introspection schema so the console commands stay pure presentation, turning instance
    // cross-saga-aware, timers, and outbox into snapshots.
    $services->set(SagaInspectionGateway::class);

    $services->set(PgAdvisoryFence::class);
    $services->alias(SagaStepUnitOfWork::class, PgAdvisoryFence::class);

    // The engine writes through the sealing front, WorkflowOutbox then HopProtocol; the port below
    // is storage only. HopProtocol's MessageContext is REQUIRED: a missing ambient alias must
    // fail the container build, never degrade the hop to anonymous.
    $services->set(DbalWorkflowOutboxWriter::class);
    $services->alias(WorkflowOutboxWriter::class, DbalWorkflowOutboxWriter::class);
    $services->alias(WorkflowCommandStore::class, DbalWorkflowOutboxWriter::class);
    $services->alias(FailedWorkflowCommands::class, DbalWorkflowOutboxWriter::class);
    $services->set(HopProtocol::class);
    $services->set(WorkflowOutbox::class);

    // Circuit breaker: storage adapter, Postgres default and on-brand, plus the policy logic. The ActivityRunner
    // autowires the optional CircuitBreaker. The alias is what `storm.saga.circuit_breaker.storage` re-points:
    // both process-local and Postgres adapters are registered here, autowirable with no app-side service; the
    // Redis one is registered by the bundle instead, since only the app can name the connection it runs on.
    $services->set(DbalCircuitBreakerStorage::class);
    $services->set(InMemoryCircuitBreakerStorage::class);
    $services->alias(CircuitBreakerStorage::class, DbalCircuitBreakerStorage::class);
    $services->set(CircuitBreaker::class);

    // Business calendar: deliberately NOT bound here. A business-time deadline,
    // #[WaitFor(deadlineBusinessDays:|Hours:)], resolved on an implicit fictitious market, Mon-Fri 9-17
    // UTC with zero holidays, would be silently-wrong money-path config, so the port stays unbound until the
    // app OPTS IN via `storm.saga.calendar`, where the bundle registers ConfiguredBusinessCalendar with the
    // declared market, or via its own BusinessCalendar registration. Unbound, the first business arm fails
    // loud with BusinessCalendarMissing; the StepCommitter autowires the port as optional-null.

    // Recovery agent and command-outbox relay. The SagaCommandPublisher port is bound by the
    // bundle, MessengerSagaCommandPublisher to the command bus. The bundle overrides the lease / retry args
    // from storm.saga.* config; package defaults keep them working standalone.
    $services->set(TimerRunner::class);
    $services->set(SagaOutboxRelay::class);

    // One-shot console drains; a worker loop runs them repeatedly, the same shape as storm:outbox:relay.
    $services->set(RunTimersCommand::class)->tag('console.command');
    $services->set(RelaySagaOutboxCommand::class)->tag('console.command');

    // storm:saga:install: the saga tables, opt-in and separate from storm:install since Saga is opt-in.
    $services->set(InstallSagaCommand::class)->tag('console.command');

    // storm:saga:inspect: read-only introspection over instance, timers, and outbox by correlation.
    $services->set(InspectSagaCommand::class)->tag('console.command');

    // storm:saga:list: the filtered listing, the door in when the operator has no correlation id yet.
    $services->set(ListSagasCommand::class)->tag('console.command');

    // storm:saga:versions: version-pinning purge view of versions by running counts; --check gates a deploy.
    $services->set(SagaVersionsCommand::class)->tag('console.command');

    // storm:saga:cleanup, periodic maintenance: reconcile stranded sagas, the durable backstop for the
    // relay's non-durable post-commit settle signal, and prune terminal bookkeeping. Autowires the Engine.
    $services->set(SagaCleanupCommand::class)->tag('console.command');
    $services->set(SagaChildrenCommand::class)->tag('console.command');
    $services->set(SagaCancelCommand::class)->tag('console.command');

    // storm:saga:unpark: return a parked timer to the claim, the only exit that does not require the
    // saga to re-arm a timer it is itself waiting on.
    $services->set(SagaUnparkCommand::class)->tag('console.command');
    $services->set(SagaPauseCommand::class)->tag('console.command');
    $services->set(SagaResumeCommand::class)->tag('console.command');

    // storm:saga:redrive: re-send a dead-lettered command instead of cancelling a healthy saga around it.
    $services->set(SagaRedriveCommand::class)->tag('console.command');

    // storm:saga:state:migrate: the sweep that makes the lazy state migration bounded and observable;
    // each row goes through the engine's own migrate verb, never a raw UPDATE.
    $services->set(SagaStateMigrateCommand::class)->tag('console.command');

    // NB: the EventResolver port has no in-package impl, since one would couple Saga to Chronicler. The bundle
    // registers MappedEventResolver and aliases EventResolver::class to it, so WaitRunner autowires it.
};
