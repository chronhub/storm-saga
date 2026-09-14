<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * A second declaration that only fails once assembled, and for a different reason than
 * `BrokenTransitionWorkflow`: its activity class is not registered in the activity locator.
 *
 * Two distinct faults are what prove a gate collects rather than stops: one exhaustive pass must
 * name this one and the broken transition together.
 */
#[Workflow(name: 'missing_activity')]
#[Start(state: 'run')]
#[State(key: 'run', type: 'activity', activity: UnregisteredActivity::class)]
#[State(key: 'done', type: 'final')]
#[On(from: 'run', trigger: 'success', to: 'done')]
final class MissingActivityWorkflow {}
