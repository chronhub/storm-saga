<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Closure;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\UnknownState;

/**
 * The immutable, assembled workflow: its `name`, the workflow type, its `State`s by key, the `start`
 * key, the optional global deadline with its `onGlobalTimeout` target, and the rollback-failure
 * policy, `compensation`. Built once from the `#[Workflow]` class by discovery, then only read by
 * the engine.
 *
 * `$version`, default 1, and `$label` mirror the `#[Workflow]` attribute: the version is the pinning
 * key, an instance resolving its definition by `(name, version)`, and the label a human tag for ops
 * only.
 *
 * `$stateVersion` mirrors `#[Workflow(stateVersion:)]`: the declared shape of the data bags, a
 * per-NAME contract the registry enforces across every co-registered version. The engine refuses to
 * run a stored row whose state version disagrees with it, and `$stateMigrator`, the workflow's
 * optional `migrateState(int $from, array $vars): array` method bound at build, is what carries a
 * lagging row one hop from `$from` to `$from + 1`. The executor chains those hops at load, under the
 * fence inside the step's transaction, until the row reaches the declared version; a hop that throws
 * breaks the chain loudly, and no partial result is ever persisted.
 *
 * `$stateValidator` is the workflow's optional `validateState(array $vars): void` method, bound as a
 * closure at build: the engine calls it at the single write point, birth and every step alike, so an
 * engine-written key such as an extract, a fallback's vars or a signal handler's passes the same
 * gate as an activity's. It throws to refuse; the executor wraps the refusal before any write
 * commits.
 *
 * `$exposedStateKeys` mirrors `#[ExposesState]`: the compiled exposure allowlist, per-NAME like the
 * state version and agreed at registration. Empty means CLOSED, the default.
 */
final readonly class WorkflowDefinition
{
    /**
     * @param  array<string, State>  $states  every state, keyed by its `key`
     */
    public function __construct(
        public string $name,
        private array $states,
        public string $start,
        public ?int $globalTimeout = null,
        public ?string $onGlobalTimeout = null,
        public CompensationMode $compensation = CompensationMode::BestEffort,
        public int $version = 1,
        public ?string $label = null,
        public ?int $retryBudget = null,
        /** @var array<class-string, Closure(object, array<string, mixed>): SignalResult> keyed by the signal object's class */
        public array $signalHandlers = [],
        /** @var array<string, SpawnSlot> the declared spawn slots, keyed by slot; the parent's compile-time consent */
        public array $spawns = [],
        /** What a correlation may do once this workflow's run has ended; enforced by the schema at birth. */
        public CorrelationReuse $reuse = CorrelationReuse::Reject,
        public int $stateVersion = 1,
        /** @var (Closure(array<string, mixed>): void)|null throws to refuse a bag at the write point */
        public ?Closure $stateValidator = null,
        /** @var list<string> the exposure allowlist; empty = closed, the default */
        public array $exposedStateKeys = [],
        /** @var (Closure(int, array<string, mixed>): array<string, mixed>)|null one hop `from -> from+1`; the executor chains it at load */
        public ?Closure $stateMigrator = null,
        /** The declared birth delay: the saga is born at once, the first drive kicks this many seconds later; null drives at birth. */
        public ?int $startAfterSeconds = null,
    ) {}

    /**
     * The declared spawn slot, or null when this workflow never consented to a child under `$slot`;
     * the adoption proof refuses that birth as poison, never as a race.
     *
     * An exact declaration wins. Past it, a slot shaped `family-<i>` resolves to its declared
     * INDEXED family: the consent is the declaration, the member index is runtime arity, and the
     * build proved the two lookups cannot both match one slot. A `family-<i>` whose family is
     * declared but NOT indexed stays null: a single-child slot consents to exactly itself.
     */
    public function spawnFor(string $slot): ?SpawnSlot
    {
        $declared = $this->spawns[$slot] ?? null;
        if ($declared !== null) {
            return $declared;
        }

        $member = ChildCorrelation::familyOfSlot($slot);
        if ($member === null) {
            return null;
        }

        $family = $this->spawns[$member] ?? null;

        return $family !== null && $family->indexed ? $family : null;
    }

    /**
     * The handler for a user-signal object, resolved by its class; null when the workflow declares none, in
     * which case the policy drops the signal with a reason and buffers nothing.
     *
     * @return (Closure(object, array<string, mixed>): SignalResult)|null
     */
    public function signalHandlerFor(object $signal): ?Closure
    {
        return $this->signalHandlers[$signal::class] ?? null;
    }

    public function hasState(string $key): bool
    {
        return array_key_exists($key, $this->states);
    }

    /**
     * @throws UnknownState when no state is declared under `$key`
     */
    public function state(string $key): State
    {
        return $this->states[$key] ?? throw UnknownState::inWorkflow($key, $this->name);
    }

    /**
     * @return array<string, State>
     */
    public function states(): array
    {
        return $this->states;
    }

    /**
     * Whether an instance resting at `$fromState` could EVER consume `$eventClass`, the question that
     * separates an event which arrived early from one this instance will never have a use for.
     *
     * A walk of the declared graph from `$fromState`, collecting the event classes of every `WaitState`
     * still reachable. The walk is complete because a state key only ever changes two ways: a
     * `Verdict\Transition` whose target the `TransitionSelector` reads off the state's own declared
     * transitions, never a value an activity invents, and the global deadline's `onGlobalTimeout`,
     * which can fire from anywhere and so seeds the frontier too.
     *
     * Deliberately OVER-approximating: guards are not evaluated and a guarded edge counts as taken, so
     * the answer is "reachable in principle". That is the safe direction: a `false` means certainly
     * unreachable, and only a certain answer is allowed to discard a business fact. The resting state
     * itself is included: an event matching the current wait but declined by a guard is still an early
     * arrival, not a dead one.
     *
     * @param  class-string  $eventClass
     */
    public function canStillAccept(string $fromState, string $eventClass): bool
    {
        $seen = [];
        $frontier = [$fromState];

        if ($this->onGlobalTimeout !== null) {
            $frontier[] = $this->onGlobalTimeout; // fires from any state, so it is reachable from all
        }

        while ($frontier !== []) {
            $key = array_pop($frontier);
            if (isset($seen[$key])) {
                continue;
            }
            // @infection-ignore-all; equivalent: a set, only the KEY is read by the isset below, so the value is arbitrary
            $seen[$key] = true;

            $state = $this->states[$key] ?? null;
            if ($state === null) {
                continue; // an undeclared target is a build-time error elsewhere; nothing to walk here
            }

            if ($state instanceof WaitState && in_array($eventClass, $state->eventClasses, true)) {
                return true;
            }

            foreach ($state->transitions as $transition) {
                $frontier[] = $transition->to;
            }
        }

        return false;
    }
}
