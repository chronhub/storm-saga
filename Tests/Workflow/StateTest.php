<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;

final class StateTest extends TestCase
{
    #[Test]
    public function a_state_is_born_with_its_transitions_complete(): void
    {
        // no on(), no append: the edges arrive at the constructor, in declaration order, readonly after
        $guard = static fn (array $vars): bool => $vars['ok'] ?? false;

        $state = new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [
            new Transition(OnTrigger::Success, 'confirm'),
            new Transition(OnTrigger::Failure, 'refund', $guard),
        ]);

        $this->assertCount(2, $state->transitions);

        [$ok, $ko] = $state->transitions;
        $this->assertSame(OnTrigger::Success, $ok->trigger);
        $this->assertSame('confirm', $ok->to);
        $this->assertNull($ok->guard);
        $this->assertSame(OnTrigger::Failure, $ko->trigger);
        $this->assertSame('refund', $ko->to);
        $this->assertSame($guard, $ko->guard);
    }

    #[Test]
    public function activity_state_carries_its_activity_retry_and_timeout(): void
    {
        $activity = new RecordingActivity(ActivityResult::success());
        $retry = new RetryPolicy(maxAttempts: 3);
        $timeout = new Timeout(30);

        $state = new ActivityState('charge', $activity, $retry, $timeout);

        $this->assertSame('charge', $state->key);
        $this->assertSame($activity, $state->activity);
        $this->assertSame($retry, $state->retry);
        $this->assertSame($timeout, $state->timeout);
        $this->assertSame([], $state->transitions);
    }

    #[Test]
    public function wait_state_carries_its_event_matching_config(): void
    {
        $matcher = static fn (object $event, array $vars): bool => true;

        $state = new WaitState('await_approval', eventClasses: [stdClass::class], eventTypes: ['approval.granted'], matcher: $matcher, timeout: new Timeout(86400));

        $this->assertSame([stdClass::class], $state->eventClasses);
        $this->assertSame(['approval.granted'], $state->eventTypes);
        $this->assertSame($matcher, $state->matcher);
        $this->assertSame(86400, $state->timeout?->seconds);
    }

    #[Test]
    public function final_state_defaults_to_done(): void
    {
        $this->assertSame('done', new FinalState()->key);
        $this->assertSame('completed', new FinalState('completed')->key);
    }
}
