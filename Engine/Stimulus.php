<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;

/**
 * The machine-level input of one run: a delivered `event` to match, a fired `timeout`, a `schedule`
 * slot that came due carrying the slot's instant, a `replay` of a crossing a family gate owes back,
 * or `none` to run the current state, either a retry kick or chaining past the first hop. A single
 * value rather than a `(?object $event, bool $isTimeout)` pair whose meaning needed both fields read
 * together.
 */
final readonly class Stimulus
{
    /**
     * @param  class-string|null  $replayedEventClass
     */
    private function __construct(
        private ?object $event,
        private bool $timeout,
        private ?PointInTime $scheduleDueAt = null,
        private ?string $replayedEventClass = null,
    ) {}

    public static function event(object $event): self
    {
        return new self($event, false);
    }

    public static function timeout(): self
    {
        return new self(null, true);
    }

    /**
     * A schedule slot came due at `$dueAt`, the fired cadence instant and the catch-up anchor.
     */
    public static function schedule(PointInTime $dueAt): self
    {
        return new self(null, false, $dueAt);
    }

    /**
     * The crossing a family gate rested and owes back, replayed once the family completes: the class
     * alone, since the wait's match was proved and its extract applied when the conclusion was
     * absorbed, so what is left to do is route the edge and cross. It carries no event object BY
     * DESIGN, which is also what keeps it out of the settlers' way: a race, a join and the
     * compensation log's confirmation all key off what the class says, never off a payload.
     *
     * @param  class-string  $eventClass
     */
    public static function replay(string $eventClass): self
    {
        return new self(null, false, null, $eventClass);
    }

    public static function none(): self
    {
        return new self(null, false);
    }

    public function eventOrNull(): ?object
    {
        return $this->event;
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function scheduleDueAtOrNull(): ?PointInTime
    {
        return $this->scheduleDueAt;
    }

    /**
     * @return class-string|null
     */
    public function replayedEventClassOrNull(): ?string
    {
        return $this->replayedEventClass;
    }

    /**
     * The class of what triggered this run, whether a delivered event carries it or a replayed
     * crossing names it; null for every other kind. The compensation log's confirmation and the
     * wait's `#[On(onEvent:)]` routing both read exactly this much of a trigger, so the two paths
     * cannot drift apart on what a crossing means.
     *
     * @return class-string|null
     */
    public function eventClassOrNull(): ?string
    {
        return $this->event === null ? $this->replayedEventClass : $this->event::class;
    }
}
