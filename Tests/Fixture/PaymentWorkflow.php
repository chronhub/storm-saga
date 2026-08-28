<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Retry;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;

/**
 * A representative `#[Workflow]` class for the builder tests:
 *
 * - An activity state carrying a retry and a timeout;
 *
 * - An effect-gating wait state mixing an event class with an alias, plus a matcher and a heartbeat;
 *
 * - Two final states, transitions with a guard, a start, and a global timeout.
 */
#[Workflow(name: 'payment', globalTimeout: 3600, onGlobalTimeout: 'failed')]
#[Start(state: 'charge')]
#[State(key: 'charge', type: 'activity', activity: RecordingActivity::class, timeoutSeconds: 30)]
#[State(key: 'await_capture', type: 'wait')]
#[State(key: 'done', type: 'final')]
#[State(key: 'failed', type: 'final')]
#[Retry(state: 'charge', maxAttempts: 3, baseMs: 100, jitter: false)]
#[WaitFor(state: 'await_capture', events: [SampleEvent::class, 'capture.done'], matcher: 'isOurCapture', heartbeatSeconds: 600)]
#[On(from: 'charge', trigger: 'success', to: 'await_capture')]
#[On(from: 'charge', trigger: 'failure', to: 'failed')]
#[On(from: 'await_capture', trigger: 'event', to: 'done', guard: 'isLargeEnough')]
final class PaymentWorkflow
{
    /**
     * @param  array<string, mixed>  $vars
     */
    public function isOurCapture(object $event, array $vars): bool
    {
        return $event instanceof SampleEvent;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function isLargeEnough(array $vars): bool
    {
        return ($vars['amount'] ?? 0) >= 100;
    }
}
