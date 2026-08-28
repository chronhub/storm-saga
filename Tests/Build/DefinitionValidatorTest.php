<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use ArrayObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplHeap;
use stdClass;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\StateType;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Build\Declaration;
use Storm\Saga\Build\DefinitionValidator;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\RetryPolicy;
use Storm\Saga\Workflow\ScheduleState;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Stringable;

/**
 * Direct, table-level exercises of the rules catalog. The builder's rejection tests cover every rule
 * through `build()`; these pin the contract at the rules' own boundary, including the inputs a
 * well-formed builder can never produce.
 */
final class DefinitionValidatorTest extends TestCase
{
    #[Test]
    public function a_null_start_is_rejected_as_unknown(): void
    {
        // unreachable through the builder: no states throws first, any state yields a default start;
        // the rules contract still rejects it rather than trusting the caller
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/start/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('a', StateType::Final)], [], [], [], null, null, null));
    }

    #[Test]
    public function a_decorator_on_an_unknown_state_is_rejected_like_a_mistyped_one(): void
    {
        // the decorator map names a key that was never declared; same rejection as targeting a wait
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('#[Retry] in workflow "wf" targets state "ghost"');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('a', StateType::Final)], [], ['Retry' => ['ghost' => true]], [], 'a', null, null));
    }

    #[Test]
    public function a_wait_for_on_an_unknown_state_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('is not a wait state');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('a', StateType::Final)], [], [], ['ghost' => new WaitFor('ghost', stdClass::class)], 'a', null, null));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_foreign_retry_value_does_not_end_the_walk_over_the_policies_behind_it(): void
    {
        // The decorator map is shared by four labels, each holding its own kind of value, so the Retry
        // walk discriminates by type and skips what is not a policy. Ending the walk there would leave
        // every policy declared behind it unjudged. Unreachable through the builder, whose reader only
        // ever fills this map with policies; this is the rules' own boundary, where the shape allows more.
        $declared = [
            new State('one', StateType::Activity, activity: RecordingActivity::class),
            new State('two', StateType::Activity, activity: RecordingActivity::class),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxAttempts must be >= 1/');

        new DefinitionValidator()->checkDeclaration(
            new Declaration('wf', $declared, [], ['Retry' => ['one' => true, 'two' => new RetryPolicy(0)]], [], 'one', null, null),
        );
    }

    #[Test]
    public function a_coherent_declaration_passes_every_rule_silently(): void
    {
        $declared = [
            new State('charge', StateType::Activity, activity: RecordingActivity::class),
            new State('await', StateType::Wait),
            new State('done', StateType::Final),
        ];
        $ons = [
            new On('charge', OnTrigger::Success, 'await'),
            new On('await', OnTrigger::Event, 'done', onEvent: stdClass::class),
        ];

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', $declared, $ons, ['Retry' => ['charge' => true]], ['await' => new WaitFor('await', stdClass::class)], 'charge', 'done', 60));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_assembled_pass_judges_the_finished_map(): void
    {
        // a non-gating wait with a timeout below the global: both shape rules pass
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), timeout: new Timeout(30), transitions: [new Transition(OnTrigger::Success, 'done')]),
            'await' => new WaitState('await', eventClasses: [stdClass::class], timeout: new Timeout(40), transitions: [new Transition(OnTrigger::Event, 'done'), new Transition(OnTrigger::Timeout, 'done')]),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_compensatable_activity_on_a_cycle_is_rejected(): void
    {
        // until every visit carries its own execution id, a compensatable state revisited through a cycle
        // aliases its compensation records, a money-shaped audit lie. charge, then ship, then charge, cycles back.
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), compensation: new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'ship')]),
            'ship' => new ActivityState('ship', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'charge')]),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        new DefinitionValidator()->checkAssembled('wf', $states, [], null, null);
    }

    #[Test]
    public function a_cycle_through_a_later_edge_is_still_found(): void
    {
        // the DFS must walk EVERY successor: charge's first edge exits cleanly to done, and only its
        // second edge starts the path that loops back; a walk truncated to one successor per state
        // would accept this definition and let the compensation records alias at runtime
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), compensation: new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'done'), new Transition(OnTrigger::Failure, 'requeue')]),
            'requeue' => new ActivityState('requeue', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'charge')]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        new DefinitionValidator()->checkAssembled('wf', $states, [], null, null);
    }

    #[Test]
    public function a_compensatable_activity_on_an_acyclic_path_passes(): void
    {
        // the safe shape: a compensatable activity reached at most once, no edge leads back to it
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), compensation: new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'done')]),
            'done' => new FinalState('done'),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], null, null);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_retriable_wait_without_a_heartbeat_is_rejected(): void
    {
        // retriable is meaningless without a heartbeat; it only bypasses the global cap on a re-arming wait
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await')]),
            'await' => new WaitState('await', eventClasses: [stdClass::class], retriable: true, transitions: [new Transition(OnTrigger::Event, 'done')]),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/retriable/i');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    public function a_retriable_heartbeat_gating_wait_passes(): void
    {
        // the legal shape: a gating heartbeat, a timeout with no finalize edge, that is also retriable
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await')]),
            'await' => new WaitState('await', eventClasses: [stdClass::class], timeout: new Timeout(30), retriable: true, transitions: [new Transition(OnTrigger::Event, 'done')]),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);

        $this->expectNotToPerformAssertions();
    }

    // schedule states

    #[Test]
    public function a_schedule_for_on_a_non_schedule_state_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('is not a schedule state');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('a', StateType::Final)], [], [], [], 'a', null, null, ['a' => new Schedule('a', intervalSeconds: 60)]));
    }

    #[Test]
    public function a_schedule_workflow_with_a_global_timeout_is_rejected(): void
    {
        // a recurring saga has no overall deadline; it is a durable entity, not a bounded run
        $declared = [new State('tick', StateType::Schedule), new State('done', StateType::Final)];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/globalTimeout/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', $declared, [], [], [], 'tick', null, 3600, ['tick' => new Schedule('tick', intervalSeconds: 60)]));
    }

    #[Test]
    public function a_schedule_with_more_than_one_cadence_source_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/more than one/i'); // ambiguous, not missing; pins the ternary branch

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, cron: '0 * * * *')]));
    }

    #[Test]
    public function two_per_instance_cron_sources_count_as_more_than_one(): void
    {
        // proves the source count includes the per-instance forms, not just interval/cron
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/more than one/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', cronVar: 'a', cronMethod: 'b')]));
    }

    #[Test]
    public function an_interval_and_a_cron_source_across_families_count_as_more_than_one(): void
    {
        // proves the count spans BOTH families: a per-instance interval plus a per-instance cron is
        // still ambiguous, not one-of-each
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/more than one/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalMethod: 'a', cronVar: 'b')]));
    }

    #[Test]
    public function a_per_instance_interval_source_alone_is_coherent(): void
    {
        $this->expectNotToPerformAssertions();

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalVar: 'review_interval')]));
    }

    #[Test]
    public function a_schedule_with_no_cadence_source_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/no cadence source/i'); // missing, not ambiguous; pins the ternary branch

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick')]));
    }

    #[Test]
    public function a_wait_mixing_a_wall_clock_and_a_business_deadline_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/one deadline mode/i');

        new DefinitionValidator()->checkDeclaration(new Declaration(
            'wf',
            [new State('await', StateType::Wait), new State('expired', StateType::Final)],
            [], [],
            ['await' => new WaitFor('await', stdClass::class, deadlineSeconds: 60, onDeadline: 'expired', deadlineBusinessDays: 2)],
            'await', null, null,
        ));
    }

    #[Test]
    public function a_business_deadline_without_a_target_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/onDeadline/i');

        new DefinitionValidator()->checkDeclaration(new Declaration(
            'wf',
            [new State('await', StateType::Wait)],
            [], [],
            ['await' => new WaitFor('await', stdClass::class, deadlineBusinessDays: 2)], // no onDeadline
            'await', null, null,
        ));
    }

    #[Test]
    public function a_schedule_with_a_non_positive_interval_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('needs a positive interval');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 0)]));
    }

    #[Test]
    public function a_schedule_with_a_non_positive_spread_is_rejected(): void
    {
        // a zero spread would divide the offset derivation by zero at the tick; the build rejects it
        // up front; "no spread" is spelled null, not 0
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/positive spread/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, spreadSeconds: 0)]));
    }

    #[Test]
    public function a_spread_of_one_second_is_the_smallest_legal_spread(): void
    {
        // pins the boundary: 1 is legal, every offset degenerating to 0, a declared-but-inert spread,
        // 0 is not. The rule is < 1, not <= 1.
        $this->expectNotToPerformAssertions();

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, spreadSeconds: 1)]));
    }

    #[Test]
    public function a_schedule_with_an_invalid_cron_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        // the SYNTAX refusal specifically; /cron/i also matched the unsatisfiable-cron message two lines
        // further down the rule, so dropping this throw altogether still passed: the expression simply fell
        // through to the parse attempt, which threw the OTHER refusal. The test caught an exception, just
        // never the one it named.
        $this->expectExceptionMessageMatches('/is not a valid cron expression/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', cron: 'not a cron')]));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_parseable_but_unsatisfiable_cron_is_rejected_at_build(): void
    {
        // '0 0 30 2 *' PASSES the parse check, February 30th being grammatically fine, yet no occurrence
        // ever comes; unrejected here, the poison would surface as a rolled-back tick in production
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/never be satisfied/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', cron: '0 0 30 2 *')]));
    }

    #[Test]
    public function a_rare_but_satisfiable_cron_is_accepted(): void
    {
        // boundary: Feb 29 fires in leap years; RARE must not be confused with NEVER, the walk finds one
        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', cron: '0 0 29 2 *')]));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_bounded_catch_up_without_a_limit_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/catchUpLimit/i');

        // idempotentTick set so the limit guard is what bites, not the idempotence one
        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, catchUp: CatchUp::ReplayBounded, idempotentTick: true)]));
    }

    #[Test]
    public function a_replaying_catch_up_without_an_idempotent_tick_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/idempotent/i');

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, catchUp: CatchUp::ReplayAll)]));
    }

    #[Test]
    public function a_schedule_state_without_a_schedule_edge_is_rejected(): void
    {
        // the assembled pass: a schedule state whose fired tick has nowhere to go. A non-schedule state FIRST
        // pins the loop's `continue`; a `break` would stop before reaching the edgeless schedule state.
        $states = [
            'other' => new FinalState('other'),
            'tick' => new ScheduleState('tick', new IntervalCadence(60), new CatchUpPolicy(CatchUp::Skip)),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/schedule/i');

        new DefinitionValidator()->checkAssembled('wf', $states, [], null, null);
    }

    #[Test]
    public function a_coherent_schedule_declaration_passes(): void
    {
        $declared = [
            new State('tick', StateType::Schedule),
            new State('charge', StateType::Activity, activity: RecordingActivity::class),
            new State('done', StateType::Final),
        ];
        $ons = [new On('tick', OnTrigger::Schedule, 'charge')];

        new DefinitionValidator()->checkDeclaration(new Declaration('wf', $declared, $ons, [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 60, catchUp: CatchUp::ReplayBounded, catchUpLimit: 3, idempotentTick: true)]));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_one_second_interval_is_accepted_at_the_boundary(): void
    {
        // intervalSeconds=1 is the smallest valid interval; the `< 1` guard must accept it, a `<=` mutant would reject it
        new DefinitionValidator()->checkDeclaration(new Declaration('wf', [new State('tick', StateType::Schedule)], [], [], [], 'tick', null, null, ['tick' => new Schedule('tick', intervalSeconds: 1)]));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_unguarded_path_rule_judges_only_the_schedule_edges(): void
    {
        // an input a well-formed builder can never produce, since the trigger matrix forbids foreign edges on a
        // schedule state: an UNGUARDED foreign edge must not count as the cadence's escape path
        $states = [
            'cadence' => new ScheduleState('cadence', new IntervalCadence(60), new CatchUpPolicy(CatchUp::Skip, null, null), [
                new Transition(OnTrigger::Success, 'done'), // unguarded, but NOT a schedule edge
                new Transition(OnTrigger::Schedule, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/every schedule edge is guarded/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], null, null);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_accepted_event_class_with_no_covering_edge_is_refused(): void
    {
        // the wait accepts TWO classes, the only edge is scoped to one: a delivered ArrayObject would
        // match as accepted, route nowhere, and HALT the saga permanently; refused at build instead
        $states = [
            'await' => new WaitState('await', eventClasses: [stdClass::class, ArrayObject::class], transitions: [
                new Transition(OnTrigger::Event, 'done', onEvent: stdClass::class),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts "ArrayObject" but keeps no unguarded route/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_wait_whose_every_event_edge_is_guarded_is_refused(): void
    {
        // the schedule twin's exact scenario on a wait: all guards rejecting a MATCHED event would
        // halt + roll back the saga on legitimate business content
        $states = [
            'await' => new WaitState('await', eventClasses: [stdClass::class], transitions: [
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts "stdClass" but keeps no unguarded route/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_state_that_is_not_a_wait_does_not_end_the_route_walk(): void
    {
        // the rule walks every state and skips those that are not waits; an activity is what almost
        // every workflow declares first, so a walk ending on the first skip would clear a definition
        // whose only wait halts on its own accepted class
        $states = [
            'charge' => new ActivityState('charge', new RecordingActivity(ActivityResult::success()), transitions: [new Transition(OnTrigger::Success, 'await')]),
            'await' => new WaitState('await', eventClasses: [stdClass::class], transitions: [
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts "stdClass" but keeps no unguarded route/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_abstract_accepted_class_does_not_end_the_walk_over_the_classes_behind_it(): void
    {
        // an abstract accepted class is skipped, the delivered event being concrete by construction;
        // ending the walk there would leave every class declared after it unchecked on the same wait
        $states = [
            'await' => new WaitState('await', eventClasses: [SplHeap::class, stdClass::class], transitions: [
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts "stdClass" but keeps no unguarded route/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    public function the_wait_route_rule_judges_only_the_event_edges(): void
    {
        // the twin of `the_unguarded_path_rule_judges_only_the_schedule_edges`, and it was missing: an
        // unguarded FOREIGN edge, a timeout route say, is not a way for an accepted event to be
        // consumed, so it must not count as the wait's escape path. Counting it would let a wait whose
        // every event route is guarded pass the build and halt on a legitimate business event.
        $states = [
            'await' => new WaitState('await', eventClasses: [stdClass::class], transitions: [
                new Transition(OnTrigger::Timeout, 'done'), // unguarded, but NOT an event edge
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts "stdClass" but keeps no unguarded route/');

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);
    }

    #[Test]
    public function a_guarded_exact_edge_with_an_unguarded_catch_all_passes(): void
    {
        // the legitimate shape the rule must keep: guards route conditionally ABOVE an unguarded
        // catch-all, so a rejecting guard falls through instead of halting
        $states = [
            'await' => new WaitState('await', eventClasses: [stdClass::class, ArrayObject::class], transitions: [
                new Transition(OnTrigger::Event, 'review', static fn (array $vars): bool => true, onEvent: stdClass::class),
                new Transition(OnTrigger::Event, 'done'),
            ]),
            'review' => new FinalState('review'),
            'done' => new FinalState('done'),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_unguarded_route_rule_judges_concrete_classes_only(): void
    {
        // an accepted INTERFACE names a family whose members are not statically enumerable: the wait
        // routes the members it knows, and an unknown member halting is the documented residual of
        // onEventTargetsAConcreteClass; the rule stands down instead of demanding a catch-all
        $states = [
            'await' => new WaitState('await', eventClasses: [Stringable::class], transitions: [
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function the_unguarded_route_rule_skips_a_wait_declaring_type_aliases(): void
    {
        // an alias resolves at runtime, so the accepted set is not statically known: the same skip
        // as waitsRouteTheirEvents; the rule must not judge what it cannot enumerate
        $states = [
            'await' => new WaitState('await', eventClasses: [stdClass::class], eventTypes: ['order.placed'], transitions: [
                new Transition(OnTrigger::Event, 'done', static fn (array $vars): bool => true),
            ]),
            'done' => new FinalState('done'),
        ];

        new DefinitionValidator()->checkAssembled('wf', $states, [], 60, null);

        $this->expectNotToPerformAssertions();
    }
}
