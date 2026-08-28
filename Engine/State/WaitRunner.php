<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\EventResolver;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Engine\Verdict\Halt;
use Storm\Saga\Engine\Verdict\Noop;
use Storm\Saga\Engine\Verdict\Stay;
use Storm\Saga\Engine\Verdict\Transition;
use Storm\Saga\Exception\MissingExtractField;
use Storm\Saga\Workflow\WaitState;

/**
 * Runs a wait state. A fired timer takes the `Timeout` transition. A delivered event that matches,
 * by class, by alias, then the optional matcher, applies the wait's declared `extract`, if any, into
 * `vars` and takes the `Event` transition, routed by the matched event's class: an `#[On(onEvent:)]`
 * edge for that class wins over a catch-all. An unmatched event is a `Noop`; the timer armed at entry
 * stands untouched, since re-arming would let correlated chatter starve a deadline. The wait's timer
 * arms on entry only, stimulus none; the escalator owns the heartbeat re-arm. With no matching
 * transition where one is expected, the saga halts.
 *
 * One trigger crosses without matching anything: the crossing a family gate rested and owes back,
 * which names a class and no object. It matched once already, when the conclusion was absorbed, and
 * its extract landed then; replaying the match against vars that extract has since moved would judge
 * the arrival against a state it helped create.
 */
final readonly class WaitRunner
{
    private WaitVarExtractor $extraction;

    public function __construct(
        private TransitionSelector $selector,
        private EventResolver $events,
        ?WaitVarExtractor $extraction = null,
    ) {
        // optional for the manual rigs: the same resolver either way, so nothing can diverge
        // @infection-ignore-all; equivalent: both operands build the same extractor, so the order cannot matter
        $this->extraction = $extraction ?? new WaitVarExtractor($events);
    }

    /**
     * Returns a verdict:
     *
     * - `Transition`: a matched event, routed by its class, a replayed crossing routed the same way,
     *   or a fired timer with a timeout edge.
     *
     * - `Halt`: a matched trigger with no edge to take, an event matched but unrouted, or a timer
     *   fired on an edgeless wait.
     *
     * - `Stay`: the entry into a timed wait, stimulus none at that hop, arming its timer.
     *
     * - `Noop`: an unmatched event, timed or not, where the standing timer is not touched, or a bare
     *   untimed entry, with nothing to do and nothing written.
     *
     * @throws MissingExtractField when a map-declared field is absent from the matched event's payload
     */
    public function run(StateContext $ctx): Transition|Stay|Halt|Noop
    {
        $state = $ctx->state;
        if (! $state instanceof WaitState) {
            return new Halt; // defensive: the machine dispatches by subtype
        }

        if ($ctx->stimulus->isTimeout()) {
            return $this->transitionOrHalt($state, OnTrigger::Timeout, $ctx->vars);
        }

        $replayed = $ctx->stimulus->replayedEventClassOrNull();
        if ($replayed !== null) {
            // A crossing the family gate rested and owes back. Everything a first arrival does BEFORE
            // the edge was already done at that rest: the wait matched, and the extract landed into
            // the vars this run reads. What is left is the routing, by the same class the first
            // arrival routed on, so the saga leaves the wait exactly where it would have left it.
            return $this->transitionOrHalt($state, OnTrigger::Event, $ctx->vars, $replayed);
        }

        $event = $ctx->stimulus->eventOrNull();
        if ($event !== null) {
            if ($this->matches($state, $event, $ctx->vars)) {
                // extraction shared with the JoinSettler's partial arrivals: one landing semantics
                $vars = $this->extraction->apply($state, $event, $ctx->vars);

                return $this->transitionOrHalt($state, OnTrigger::Event, $vars, $event::class);
            }

            // an UNMATCHED event never touches the wait's timer: the deadline armed at entry STANDS.
            // Re-arming here would let correlated chatter, whether at-least-once redeliveries or other saga
            // events on the same correlation, push a business deadline forever: starvation. The lost
            // self-healing, a vanished timer row re-armed by traffic, is the cleanup's job, not noise's.
            return new Noop;
        }

        if ($state->timeout !== null) {
            // entry, or chaining back in, stimulus none: arm the wait's timer, the ONLY arm site
            // besides the escalator's re-arm
            $op = $state->timeout->isBusiness()
                ? TimerOp::armBusinessTimeout($state->key, $state->timeout->businessDays, $state->timeout->businessHours)
                : TimerOp::armTimeout($state->key, $state->timeout->seconds);

            return new Stay([$op], $ctx->vars);
        }

        return new Noop;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function matches(WaitState $state, object $event, array $vars): bool
    {
        return $this->extraction->matches($state, $event, $vars);
    }

    /**
     * @param  array<string, mixed>  $vars
     * @param  class-string|null  $eventClass  the matched event's class, event trigger only, routes `#[On(onEvent:)]`
     */
    private function transitionOrHalt(WaitState $state, OnTrigger $trigger, array $vars, ?string $eventClass = null): Transition|Halt
    {
        $to = $this->selector->select($state, $trigger, $vars, $eventClass);

        return $to === null
            ? new Halt
            : new Transition($to, $trigger, $vars);
    }
}
