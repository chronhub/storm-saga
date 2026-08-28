<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\State;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\State\TransitionSelector;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;

final class TransitionSelectorTest extends TestCase
{
    #[Test]
    public function picks_the_first_transition_matching_the_trigger(): void
    {
        $selector = new TransitionSelector;
        $state = new ActivityState('s', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'a'), new Transition(OnTrigger::Failure, 'b')]);

        $this->assertSame('a', $selector->select($state, OnTrigger::Success, []));
        $this->assertSame('b', $selector->select($state, OnTrigger::Failure, []));
        $this->assertNull($selector->select($state, OnTrigger::Timeout, []));
    }

    #[Test]
    public function skips_a_transition_whose_guard_fails_and_falls_through(): void
    {
        $selector = new TransitionSelector;
        $state = new ActivityState('s', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'big', static fn (array $vars): bool => ($vars['amount'] ?? 0) > 100), new Transition(OnTrigger::Success, 'small')]);

        $this->assertSame('small', $selector->select($state, OnTrigger::Success, ['amount' => 50]));
        $this->assertSame('big', $selector->select($state, OnTrigger::Success, ['amount' => 200]));
    }

    #[Test]
    public function routes_an_event_trigger_by_the_matched_event_class(): void
    {
        $selector = new TransitionSelector;
        $state = new WaitState('await', transitions: [new Transition(OnTrigger::Event, 'paid', onEvent: SampleEvent::class), new Transition(OnTrigger::Event, 'declined', onEvent: stdClass::class)]);

        $this->assertSame('paid', $selector->select($state, OnTrigger::Event, [], SampleEvent::class));
        $this->assertSame('declined', $selector->select($state, OnTrigger::Event, [], stdClass::class));
        // an event with no scoped transition and no catch-all yields null: the handler halts
        $this->assertNull($selector->select($state, OnTrigger::Event, [], DateTimeImmutable::class));
    }

    #[Test]
    public function an_exact_onevent_match_wins_over_a_catch_all_declared_first(): void
    {
        $selector = new TransitionSelector;
        $state = new WaitState('await', transitions: [
            new Transition(OnTrigger::Event, 'fallback'),                          // catch-all, declared FIRST
            new Transition(OnTrigger::Event, 'paid', onEvent: SampleEvent::class), // scoped, declared SECOND
        ]);

        $this->assertSame('paid', $selector->select($state, OnTrigger::Event, [], SampleEvent::class)); // scoped wins
        $this->assertSame('fallback', $selector->select($state, OnTrigger::Event, [], stdClass::class)); // else catch-all
    }

    #[Test]
    public function the_scoped_pass_honours_the_trigger_not_only_the_event_class(): void
    {
        // the scoped first-pass needs BOTH a trigger match AND an onEvent match; a matching event class
        // must not be selected under a different trigger, where a `||` there would bypass the trigger check
        $selector = new TransitionSelector;
        $state = new WaitState('await', transitions: [new Transition(OnTrigger::Event, 'paid', onEvent: SampleEvent::class)]);

        $this->assertNull($selector->select($state, OnTrigger::Failure, [], SampleEvent::class));
    }
}
