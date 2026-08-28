<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Workflow\Retime;
use Storm\Saga\Workflow\RetimePolicy;

/**
 * Grants signal handlers the right to move `$state`'s armed deadline without leaving the state, a
 * negotiated extension or a debounce that restarts the window. Undeclared, a deadline is immutable
 * once armed; that is the default and the doctrine, since letting chatter push a business deadline
 * is starvation, so the grant must be CAPPED: the build refuses a `#[Retimable]` that declares
 * neither `maxRetimes` nor `maxExtensionSeconds`.
 *
 * The target must be a wait with its own finalizing deadline. An effect-gating wait's deadline
 * belongs to the engine, and a heartbeat is liveness, not expiry; both are refused at build, the
 * retime twins of `engineOwnsEffectGatingDeadlines` and `heartbeatWaitsMustGate`.
 *
 * @see RetimePolicy
 * @see Retime
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Retimable
{
    /**
     * @param  string  $state  the wait state whose deadline may be retimed
     * @param  int|null  $maxRetimes  per-visit cap on applied retimes; a crossing resets the count
     * @param  int|null  $maxExtensionSeconds  cap on a single retime's reach from now
     */
    public function __construct(
        public string $state,
        public ?int $maxRetimes = null,
        public ?int $maxExtensionSeconds = null,
    ) {}
}
