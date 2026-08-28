<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Priority\Priority;

/**
 * Configures a wait state: the events it accepts as FQCNs and/or stable type aliases matched on delivery,
 * an optional `$matcher` method on the workflow class `fn(object $event, array $vars): bool`, the wait's
 * deadline described below, and an optional `$extract`, the matcher's data-side twin, that pulls fields
 * from the matched event into named `vars` so an activity reads `vars['amount']` rather than a schemaless
 * event bag. `$extract` has two forms: a workflow method name `fn(object $event, array $vars): array` that
 * reads the typed event and can transform, or a declarative `['varName' => 'payloadField']` map that reads
 * the top-level fields of the wire payload.
 *
 * The deadline names its intent, so the reader knows escalate versus finalize from the field, not from the
 * wait's derived gating-ness:
 *
 * - `$heartbeatSeconds`: a liveness deadline that escalates by re-arming and announcing SagaAwaitEscalated,
 *   never finalizing. It suits an effect-gating wait, the success-target of an activity whose outcome may
 *   merely be lagging, where finalizing could discard a committed-but-unconfirmed effect and lose money.
 *   The build rejects a heartbeat on a non-gating wait.
 *
 * - `$deadlineSeconds` with `$onDeadline`: a wall-clock deadline that finalizes; on expiry the saga takes a
 *   timeout edge to `$onDeadline`. Only legal on a non-gating wait reached by an event; the build rejects
 *   it on an effect-gating one. Each of the pair requires the other.
 *
 * - `$deadlineBusinessDays` or `$deadlineBusinessHours` with `$onDeadline`: the same finalized deadline in
 *   business time, such as "T+2 business days" or "4 business hours", resolved through a `BusinessCalendar`
 *   that skips weekends, holidays, and out-of-hours instead of counting wall-clock seconds.
 *
 * A wait declares at most one deadline mode: heartbeat XOR wall-clock-deadline XOR business-deadline.
 *
 * `$retriable` refines a `$heartbeatSeconds` wait: when its success is inevitable post-pivot, the effect
 * once it runs cannot be rejected, such as capturing a confirmed hold, the engine lets it re-arm forever,
 * ignoring the instance-wide global cap `HaltAtGlobalCap` instead of halting it for recon. The heartbeat's
 * SagaAwaitEscalated stays the liveness signal. Only legal with `$heartbeatSeconds` on a gating wait; the
 * build rejects it otherwise.
 *
 * `$lane` declares the criticality of this wait's incoming signals, an opaque ordinal where higher is
 * more urgent, the event-side twin of `Prioritized` on commands: the awaited event classes publish to
 * the signal lane the app maps this level to, ahead of lower levels. Declared PER WAIT on purpose, so
 * the alternatives of one wait, such as captured versus voided, always ride the same lane; the event
 * class itself never carries a lane, a saga concept stays off domain facts. Across workflows the same
 * event class takes the HIGHEST declared level, finish-over-start. Null rides the default signal lane.
 * Storm names no levels and caps no cardinality; pass a typed {@see Priority} or a bare int.
 *
 * `$correlateBy` names the top-level wire payload field carrying the saga correlation key for this
 * wait's events. Declare it on an EXTERNAL-event wait: an awaited fact not caused by a command of
 * this saga carries no ambient trace correlation, so the generic router cannot resolve the target
 * instance without it. The declaration takes total precedence for these classes, the ambient
 * correlation is ignored, because an externally caused fact may carry the trace of whichever actor
 * triggered it, never the awaited saga's. The field name is the durable wire contract, the same
 * top-level payload read as the declarative form of `$extract`; every alternative of the wait reads
 * the SAME field, and a field missing or empty at delivery fails loud instead of dropping the
 * outcome. Null keeps the ambient-correlation routing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class WaitFor
{
    /** @var list<string> class-strings and/or short type aliases */
    public array $events;

    /** The signal-lane ordinal this wait's events ride at; higher is more urgent, null rides the default signal lane. */
    public ?int $lane;

    /**
     * @param  string|array<string>  $events  one or many event class-strings / type aliases
     * @param  int|null  $heartbeatSeconds  a liveness deadline that escalates, gating waits only
     * @param  bool  $retriable  a gating heartbeat wait whose success is inevitable post-pivot; re-arm past the global cap instead of halting; only legal with `$heartbeatSeconds`
     * @param  int|null  $deadlineSeconds  a wall-clock deadline that finalizes to `$onDeadline`, non-gating waits only
     * @param  string|null  $onDeadline  the state the wall-clock or business deadline drives to
     * @param  string|array<string, string>|null  $extract  a method name, the typed-event twin of the matcher, or a `varName => payloadField` map
     * @param  int|null  $deadlineBusinessDays  a business-time deadline in business days, resolved via a `BusinessCalendar`
     * @param  int|null  $deadlineBusinessHours  a business-time deadline in business hours, resolved via a `BusinessCalendar`
     * @param  Priority|int|null  $lane  the signal-lane level of this wait's events, an opaque ordinal where higher is more urgent; null rides the default signal lane
     * @param  string|null  $correlateBy  the top-level wire payload field carrying the saga correlation key, for an external-event wait whose facts carry no ambient trace correlation; null keeps the ambient-correlation routing
     */
    public function __construct(
        public string $state,
        string|array $events,
        public ?string $matcher = null,
        public ?int $heartbeatSeconds = null,
        public bool $retriable = false,
        public ?int $deadlineSeconds = null,
        public ?string $onDeadline = null,
        public string|array|null $extract = null,
        public ?int $deadlineBusinessDays = null,
        public ?int $deadlineBusinessHours = null,
        Priority|int|null $lane = null,
        public ?string $correlateBy = null,
    ) {
        $this->events = is_string($events) ? [$events] : array_values($events);
        $this->lane = $lane instanceof Priority ? $lane->level() : $lane;
    }
}
