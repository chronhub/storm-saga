<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\ExposesState;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * Version 1 of a workflow whose activity belongs to it alone, the counterpart of `AuditWorkflowV2`.
 *
 * The pair exists so a resolution can be aimed at one version and checked for what it did not build:
 * the other version's class, and every activity outside this graph.
 */
#[Workflow(name: 'audit', version: 1, label: 'first_pass')]
#[ExposesState('reference')]
#[Start(state: 'review')]
#[State(key: 'review', type: 'activity', activity: AuditActivity::class)]
#[State(key: 'done', type: 'final')]
#[On(from: 'review', trigger: 'success', to: 'done')]
final class AuditWorkflow {}
