<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Declares, at compile time, a child workflow this parent CONSENTS to engender: the slot the spawn
 * command will name, the child workflow type expected under it, and the wait that consumes the
 * child's conclusion. Repeatable, one per slot.
 *
 * Two guards read this declaration:
 *
 * - At birth, the adoption proof reads the parent's pinned definition and refuses a slot the parent
 *   never declared, or a child type other than the declared one; this is the CONSENT half of the
 *   parenthood proof, taken in the same transaction as the existence half, under the same shared
 *   lock.
 *
 * - At build, the declaration must name its terminal wait `awaitedBy`: a spawned child concludes
 *   into a wait the parent declares, never into a completion that would orphan it. A nominal settle
 *   with a living child stays refused at runtime with `ChildrenStillRunning`, which names the wait
 *   that should have consumed the child. The build also refuses a spawning parent that declares
 *   `reuse: Allow`: a child correlation is minted from the parent correlation and the slot alone,
 *   with no generation, so a second run of the parent would remint the first run's children and
 *   count their claims as its own.
 *
 * WHO dispatches the `StartChildWorkflow` is deliberately not constrained: a parent activity or an
 * external composer both spawn under the same declaration; consent belongs to the parent's
 * definition, not to the dispatch site.
 *
 * `$indexed` declares a FAMILY instead of one child: the DECLARATION stays static, one attribute
 * for one consent, while the ARITY becomes runtime data. Spawn commands then name the members
 * `slot-0 … slot-n`, the consent proof accepts any `slot-<i>` under this declaration, and every
 * member concludes into the SAME `awaitedBy` wait, which only crosses once every spawned member is
 * terminal. A conclusion arriving before that is ABSORBED, its vars landing exactly as a crossing
 * would land them, and the crossing it owes back is parked on the row; each member's terminal settle
 * then pokes the parent to spend it. The application therefore owes no ordering between a member's
 * conclusion and that member's own settle, which is the one contract it could not honor: nothing
 * tells a parent that a child concluded, so the fact is published by the application and the order
 * is weather. The ceilings still bound the whole: `MAX_CHILDREN` caps members and siblings together,
 * bounded by design surviving the runtime arity. A family serves the COORDINATED N, where the
 * parent must join and dispose, never batch throughput: one child per item of a large dataset
 * remains the batch smell, and N independent sagas with an applicative correlation remain the
 * cheaper, more scalable answer for it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Spawns
{
    public function __construct(
        /** A static identifier following the `ChildCorrelation` slot grammar, never runtime data. */
        public string $slot,
        /** The child's registered workflow name. */
        public string $workflow,
        /** A declared wait state of THIS workflow. */
        public string $awaitedBy,
        public bool $indexed = false,
    ) {}
}
