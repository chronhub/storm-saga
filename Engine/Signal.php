<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;

/**
 * The trigger of one saga step, as a value: what woke the saga, the SignalKind, plus the data that
 * kind carries. That data is the delivered `event`, the timer's `expectedStateKey` as the
 * stale-state guard, a due schedule slot's `scheduleDueAt`, or a start's initial `vars` and
 * `context`. Built only through the per-kind factories, so construction guarantees a kind's fields;
 * an `Event` signal always carries its event.
 *
 * @see StepPolicy
 */
final readonly class Signal
{
    /**
     * @param  array<string, mixed>  $vars
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public SignalKind $kind,
        public ?object $event = null,
        public ?string $expectedStateKey = null,
        public array $vars = [],
        public array $context = [],
        public ?string $causationId = null,
        public ?string $reason = null,
        public bool $force = false,
        public ?PointInTime $scheduleDueAt = null,
        public ?string $failedMessageId = null,
        public ?EffectProvenance $provenance = null,
    ) {}

    /**
     * @param  array<string, mixed>  $vars  initial state bag
     * @param  array<string, mixed>  $context  ambient context handed to activities, such as actor or origin
     */
    public static function start(array $vars = [], array $context = [], ?string $causationId = null): self
    {
        return new self(SignalKind::Start, vars: $vars, context: $context, causationId: $causationId);
    }

    public static function event(object $event, ?string $causationId = null): self
    {
        return new self(SignalKind::Event, event: $event, causationId: $causationId);
    }

    /**
     * @param  string|null  $expectedStateKey  the state the timer was armed for; null means no stale guard
     */
    public static function stateTimeout(?string $expectedStateKey, ?string $causationId = null): self
    {
        return new self(SignalKind::StateTimeout, expectedStateKey: $expectedStateKey, causationId: $causationId);
    }

    /**
     * @param  string|null  $expectedStateKey  the state the kick was armed for; null means no stale guard
     */
    public static function kick(?string $expectedStateKey, ?string $causationId = null): self
    {
        return new self(SignalKind::Kick, expectedStateKey: $expectedStateKey, causationId: $causationId);
    }

    /**
     * A fired cadence slot for a schedule state. `$expectedStateKey` is the stale-state guard, so the
     * slot is ignored if the saga already left the schedule state; `$dueAt` is the slot's instant.
     */
    public static function schedule(string $expectedStateKey, PointInTime $dueAt, ?string $causationId = null): self
    {
        return new self(SignalKind::Schedule, expectedStateKey: $expectedStateKey, causationId: $causationId, scheduleDueAt: $dueAt);
    }

    /**
     * An application-level signal object delivered to a live saga; it rides the `event` slot and the
     * kind discriminates. The handler is resolved by the object's class on the workflow definition.
     */
    public static function user(object $signal, ?string $causationId = null): self
    {
        return new self(SignalKind::User, event: $signal, causationId: $causationId);
    }

    public static function globalDeadline(?string $causationId = null): self
    {
        return new self(SignalKind::GlobalDeadline, causationId: $causationId);
    }

    /**
     * @param  string|null  $failedMessageId  the sealed id of the dead-lettered command; null when the
     *                                        caller cannot name it, a corrupt row or a pre-upgrade
     *                                        stamp, and the pairing guard treats unknown as unpaired,
     *                                        never as a settle
     */
    public static function effectFailure(?string $causationId = null, ?string $failedMessageId = null, ?EffectProvenance $provenance = null): self
    {
        return new self(SignalKind::EffectFailure, causationId: $causationId, failedMessageId: $failedMessageId, provenance: $provenance);
    }

    /**
     * A spawned member of an indexed family settled terminally: the parent may now owe itself a
     * crossing its family gate rested. Carries nothing but its causation, everything the step needs
     * being on the parent's own row and in the member counts the gate re-reads from truth.
     */
    public static function familyPoke(?string $causationId = null): self
    {
        return new self(SignalKind::FamilyPoke, causationId: $causationId);
    }

    /**
     * An operator's cancel: stop this saga and roll back what is safe to undo. `$force` overrides
     * the in-flight-effect refusal at an effect-gating wait, the in-flight step itself is still
     * never blindly compensated; an unconfirmed step is skipped and flagged.
     */
    public static function cancel(?string $reason = null, bool $force = false, ?string $causationId = null): self
    {
        return new self(SignalKind::Cancel, causationId: $causationId, reason: $reason, force: $force);
    }
}
