<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * A declaration whose metadata is impeccable and whose graph is not: the transition leaves for a
 * state nobody declared.
 *
 * The shape a lazy registry has to be judged on. Its `#[Workflow]` line is readable at compile time,
 * so the index accepts it and the build never sees the fault; only assembling the graph does.
 */
#[Workflow(name: 'broken_transition')]
#[Start(state: 'run')]
#[State(key: 'run', type: 'activity', activity: RecordingActivity::class)]
#[State(key: 'done', type: 'final')]
#[On(from: 'run', trigger: 'success', to: 'nowhere')]
final class BrokenTransitionWorkflow {}
