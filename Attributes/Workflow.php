<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\CompensationMode;
use Storm\Saga\Workflow\CorrelationReuse;

/**
 * Declares a class as a saga workflow: its stable `name`, the `workflowType`, and an optional global
 * deadline of `globalTimeout` seconds after which it transitions to `onGlobalTimeout`. The class itself
 * carries no behavior; it is a declarative container for `#[State]`, `#[On]`, `#[WaitFor]`, and `#[Retry]`,
 * and holds no behavioral attributes by rule. A class-level `#[Prioritized]` beside it declares the
 * workflow's default priority for every command it issues, compiled at container build and keyed by `name`;
 * co-registered versions of one name must agree on the level. It declares posture, not behavior.
 *
 * `$version` defaults to 1 and pins the definition: an instance is born under the latest registered version
 * and lives and dies under it, so an evolved definition under a new version coexists with the in-flight
 * instances of the old one; the old class stays co-registered until its instances drain. `$label` is an
 * optional human tag for that version, such as `'fraud_check'`, descriptive only and surfaced by ops; the
 * resolution key is the int, never the label.
 *
 * `$compensation` picks the rollback failure policy `CompensationMode`: `BestEffort` by default flags a
 * failed undo and keeps going, or `Strict` stops at the first failed undo.
 *
 * `$retryBudget` defaults to null for unlimited and caps the total activity re-attempts across the whole
 * saga, the sum of the per-state `retries`: when it is reached, a further per-state retry is denied and the
 * activity fails, a workflow-level backstop against retry churn above the per-state `#[Retry(maxAttempts:)]`.
 *
 * `$reuse` declares what the correlation may do once a run of this workflow has ENDED: `Reject` by
 * default spends it once, `Allow` lets the same business key run again under the next generation. It is
 * enforced by the schema at birth, never by a runtime check. A workflow that declares `#[Spawns]`
 * keeps the default: the build refuses `Allow` on a spawning parent, since a child correlation
 * carries no generation and a second run would remint the first run's children.
 *
 * `$stateVersion`, default 1, names the shape of the DATA bags this workflow's activities read and
 * write, a THIRD axis beside `$version`: the definition version pins the GRAPH at birth and never
 * moves, while the state version is stamped at birth and is the one axis a stored row may later be
 * migrated across while alive. It is keyed by `name`, not by definition version, because co-registered
 * versions of one name share their activities and therefore ONE data contract; they must declare the
 * same value, the same agreement law as the class-level `#[Prioritized]`.
 *
 * @see CorrelationReuse the policy, and what each regime actually guarantees
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Workflow
{
    public function __construct(
        public string $name,
        public int $version = 1,
        public ?string $label = null,
        public ?int $globalTimeout = null,
        public ?string $onGlobalTimeout = null,
        public CompensationMode $compensation = CompensationMode::BestEffort,
        public ?int $retryBudget = null,
        public CorrelationReuse $reuse = CorrelationReuse::Reject,
        public int $stateVersion = 1,
    ) {}
}
