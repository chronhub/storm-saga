<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\ExposesState;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * Version 2 of `audit`, co-registered beside version 1: the evolved graph a new instance is born
 * under while the pinned instances of version 1 keep resolving their own.
 *
 * It agrees with its sibling on the two per-name declarations, `stateVersion` and the exposure
 * allowlist, because co-registered versions share their activities and therefore one data contract.
 */
#[Workflow(name: 'audit', version: 2, label: 'second_pass')]
#[ExposesState('reference')]
#[Start(state: 'review')]
#[State(key: 'review', type: 'activity', activity: AuditActivity::class)]
#[State(key: 'escalate', type: 'activity', activity: AuditActivity::class)]
#[State(key: 'done', type: 'final')]
#[On(from: 'review', trigger: 'success', to: 'escalate')]
#[On(from: 'escalate', trigger: 'success', to: 'done')]
final class AuditWorkflowV2 {}
