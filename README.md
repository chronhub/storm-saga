# Storm Saga

Code-first **orchestration** for long-running processes: a per-instance state machine that reacts to
domain events, runs activities (synchronous and async), waits for events, arms timers, schedules
recurring slots, and applies retry, timeout, compensation, and circuit-breaker policies. Persisted on
PostgreSQL, fenced against concurrency, deduplicated via the shared `es_inbox` — durability delegated
to **Symfony Messenger + PostgreSQL** (no bespoke queue engine).

> **Orchestration, not choreography.** A saga is a *defined, central* workflow. Ad-hoc reactive
> chaining (`command → event → command`) stays the app's job (plain event handlers). Temporal is a
> documented escape hatch, never the foundation. Saga is an **opt-in package** — Chronicler / Story /
> Projector do not depend on it.

## When you reach for it

A saga is BOUNDED by design: it earns its keep when a process has a defined shape — several steps
whose partial completion must be compensated, waits on external outcomes, deadlines that mean
something. Ask "is this *the saga's*?" before adding to one: a per-event reaction, an open-ended
subscription, or state that outlives the process belongs to the app (handlers, aggregates,
projections), and the saga stays the coordinator that ends.

## The DSL

A workflow is declared on a plain class with attributes; reflection turns them into an immutable
`WorkflowDefinition` at registration (`Build/`, `WorkflowRegistry`). The set: `#[Workflow]` (name,
version — plus the instance-wide policies: `globalTimeout` and its handler, compensation mode,
retry budget, and the correlation `reuse` posture), `#[Start]` (the entry state, optionally with a
declared birth delay `afterSeconds` — born at once, first drive deferred), `#[State]` / `#[StateType]`,
`#[On]` (event-driven transition), `#[WaitFor]` (park until an event, with an optional timeout and
priority lane), `#[Retry]`, `#[Compensate]` (rollback step), `#[Fallback]`, `#[CircuitBreaker]`,
`#[Schedule]` (recurring slot, cron-backed), `#[Signal]` (nudge a resting instance without a
transition), `#[OnTrigger]`, `#[Prioritized]` (per-workflow command lane), and `#[Spawns]` (a child
workflow this parent consents to engender, with the wait that consumes its conclusion).

## Guarantees

- **One step, one transaction.** A state advance, its timer changes, and its outgoing commands commit
  or roll back together (`Engine/StepExecutor`). The machine and performers return data, never write.
- **One saga per correlation.** A `correlationId` identifies exactly one instance. `start` is
  idempotent — a second start for the same id is a no-op. Events route to the instance by correlation
  (`routeOutcome` / `deliverByCorrelation`).
- **Fenced + version-pinned.** Each step runs under a Postgres advisory fence
  (`Locking/PgAdvisoryFence`) plus an OCC version guard; an in-flight instance always runs the
  definition shape it was born under, even after a newer version is deployed.
- **At-least-once command delivery.** Issued commands land in a co-transactional outbox
  (`Outbox/`); a relay dispatches them to Messenger and a dead-letter settles the saga at its
  effect-gating wait, compensating earlier confirmed steps. Effects must therefore be
  idempotent / reconcilable.
- **Consumer-safe entry points.** An acknowledged bus consumer that feeds a saga uses `routeOutcome`
  (an outcome event) or `startOrThrow` (a start message): a held fence throws a *retryable*
  `SagaFenceBusy` instead of collapsing to a `false` that would ack-and-drop the signal. The `bool`
  variants (`start`, `deliver`, `deliverByCorrelation`) are for synchronous callers only.
- **One identity per run.** A correlation claims its identity at BIRTH and journals per generation;
  the `#[Workflow(reuse:)]` posture decides what a returning correlation means — `Reject` demands
  proof it is the same run, `Allow` accepts the window. A finished life never absorbs a new one's
  events.
- **Parenthood is proven, not assumed.** A spawned child is adopted in one transaction: the
  parent's pinned definition must CONSENT (`#[Spawns]` names the slot, the child type and the wait
  that consumes the conclusion) and the parent must EXIST — a spawn racing its parent's commit is
  `ParentNotBornYet`, retryable, never a silently orphaned child. A parent cannot settle while a
  child lives (`ChildrenStillRunning`).

Beside the DSL, the package ships one ready-made workflow: the durable semaphore (`Semaphore/`) —
at most N concurrent holders of a named resource, one guardian instance per resource (the
correlation IS the resource), `SemaphoreClient` as the whole surface (`provision` / `acquireFor` /
`release` / `renew` / `withdraw`). Acquire answers `Granted`, `Queued` or `Rejected` (the queue is
bounded by declaration) under the guardian's fence, idempotent by token — the token is the waiter's
identity; a promotion rides the guardian's outbox as a `GrantSlot` and wakes the waiter through the
same seam the outcome router uses. Grants carry a TTL: the scheduled sweep expropriates a leaked
slot (crashed holder) and promotes the queue head, `Renew` being the slow-but-alive holder's
relief. Concurrency only — calls-per-second toward a downstream stays transport-side.

## Operating it

Console (`Console/`), spelled in full because a reader copies them:

- `storm:saga:install` — the schema, verified and transactional: it either proves what it created or
  rolls back untouched.
- `storm:saga:relay` — drain the command outbox.
- `storm:saga:timers` — drive what is due.
- `storm:saga:cleanup` — reconcile stranded sagas, then prune terminal bookkeeping.
- `storm:saga:list` — the filtered listing, the way in when you have an incident and no correlation
  id: by type, status, idle time or waived budget, oldest-touched first.
- `storm:saga:inspect` — one correlation, with its timers, issued commands and rollback log.
- `storm:saga:children` — the zombie sweep: living children whose parent is settled or gone.
- `storm:saga:cancel` — cancel a running saga.
- `storm:saga:pause` — the operator freeze BEFORE the damage: stamp one instance (still a living
  row, just not executed), or freeze a whole type births included; state timers keep their
  original instants, the global deadline and the cancel verb pass through.
- `storm:saga:resume` — lift the freeze; deadlines that expired during the window fire at the next
  cycle, and facts the window dead-lettered are redrive's to send back.
- `storm:saga:unpark` — return a parked timer to the claim (the repair for a saga frozen by one
  poison row; safe, a parked timer never fired).
- `storm:saga:redrive` — re-send a dead-lettered command instead of cancelling the saga around it;
  refuses unless the effect is proven uncommitted, `--force --reason` owns the risk.
- `storm:saga:versions` — pinning counts per version, plus a deploy `--check`.
- `storm:saga:state:migrate` — drive every behind instance of one type through its declared state
  migration chain; the sweep that makes the lazy migration bounded and observable.

Tables (`Schema/`): `workflow_instances` and `workflow_correlations` — the living row and the durable
memory of every correlation ever claimed, one invariant in two tables — plus `workflow_outbox`
(+ archive), `workflow_timers`, and the circuit-breaker store.

## Contributor doctrine — the load-bearing rules

**1. Bounded by design.** New capabilities must answer "is this *the saga's*?" — anything
open-ended (per-event reactions, unbounded subscriptions, state outliving the process) is the
regression, however convenient the engine hook would be.

**2. The machine returns data, never writes.** State transitions and performers produce outcomes;
`StepExecutor` owns the one transaction. A write smuggled into the machine forks the
one-step-one-transaction guarantee.

**3. Durability is delegated.** Symfony Messenger + PostgreSQL are the queue and the store; a
bespoke delivery mechanism, however small, is a second engine to prove correct.

**4. The storage failure stays local.** `SagaStorageFailure` is a concrete class here, not a
Contracts interface: every Saga port is module-local, and hoisting the failure would create the
dependency edge the layering forbids.

## Resources

This package is developed in the `chronhub/storm` monorepo; a standalone repository for it is a
READ-ONLY subtree split. Report issues and open pull requests on the monorepo, where the tests,
the architecture gates and the full internal documentation live.

---

*Pre-version: this package changes without deprecation cycles — pin a commit if you need
stability, expect resets rather than migrations until the first tagged version.*
