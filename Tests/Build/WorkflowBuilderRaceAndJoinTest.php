<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Attributes\Compensate;
use Storm\Saga\Attributes\JoinArm;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\RaceArm;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\WaitFor;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Tests\Fixture\AbstractSettlement;
use Storm\Saga\Tests\Fixture\AlphaCommand;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\BetaCommand;
use Storm\Saga\Tests\Fixture\GammaFailed;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Tests\Fixture\SettlementConfirmed;
use Storm\Saga\Tests\Fixture\SettlementSettled;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;
use Storm\Saga\Workflow\WaitState;

final class WorkflowBuilderRaceAndJoinTest extends TestCase
{
    private function builder(): WorkflowBuilder
    {
        return new WorkflowBuilder(new ArrayContainer([
            RecordingActivity::class => new RecordingActivity(ActivityResult::success()),
        ]));
    }

    // decorators: present means able to act

    #[Test]
    public function a_two_arm_race_builds_with_its_derived_settling_wait_and_resolved_arms(): void
    {
        $wf = new #[Workflow(name: 'race')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $state = $def->state('quote');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertNotNull($state->race);
        $this->assertSame('await', $state->race->settledBy); // DERIVED from the success edge, never declared
        $this->assertSame(['alpha', 'beta'], array_keys($state->race->arms));
        $this->assertSame(AlphaCommand::class, $state->race->arms['alpha']->command);
        $this->assertSame(SettlementSettled::class, $state->race->arms['beta']->wonBy);
    }

    #[Test]
    #[Group('adversarial')]
    public function reports_a_numeric_state_key_as_the_string_it_was_declared_as(): void
    {
        // A state key is free-form, so `'2'` is legal, and PHP silently turns it into an int the
        // moment it becomes an array key. The rules cast it back before it reaches a message: without
        // that, the report of a misplaced arm dies in a TypeError instead of naming the state.
        $wf = new #[Workflow(name: 'numeric')]
        #[State(key: '2', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[Start(state: '2')]
        #[WaitFor(state: '2', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[RaceArm(state: '2', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: '2', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: '2', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/"2"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_race_arms_whose_outcomes_overlap_by_subtype(): void
    {
        // The join twin: two arms whose winning events stand in a subtype relation cannot both be
        // resolved, since the refined one satisfies the base one too. Each pair is visited once,
        // so this fixture, base declared first, exercises exactly one of the two is_a directions;
        // the reversed-order test below carries the other.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: AbstractSettlement::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [AbstractSettlement::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_race_arms_whose_outcomes_overlap_by_subtype_declared_refined_first(): void
    {
        // the other is_a direction: the refined outcome declared before its base; subtyping runs
        // one way only, so dropping either direction of the check lets one declaration order build
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: AbstractSettlement::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [AbstractSettlement::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_race_arms_sharing_a_name_on_one_state(): void
    {
        // The arm name is the key the compensation log pairs on, so two arms answering to it would
        // make one entry cover both.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'alpha', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/alpha/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function collects_race_arms_for_every_state_that_declares_them(): void
    {
        // The arm map is keyed by state, and a single-state fixture proves nothing about it: truncated
        // to its first entry it would still satisfy every test that races on one state alone, while
        // the second state's arms vanish from the built definition.
        $wf = new #[Workflow(name: 'two_races')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await_quote', type: 'wait')]
        #[State(key: 'ship', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await_ship', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'ship', arm: 'gamma', command: AlphaCommand::class, wonBy: GammaFailed::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'ship', arm: 'delta', command: BetaCommand::class, wonBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await_quote', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[WaitFor(state: 'await_ship', events: [GammaFailed::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await_quote')]
        #[On(from: 'await_quote', trigger: 'event', to: 'ship')]
        #[On(from: 'ship', trigger: 'success', to: 'await_ship')]
        #[On(from: 'await_ship', trigger: 'event', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $quote = $def->state('quote');
        $ship = $def->state('ship');

        $this->assertInstanceOf(ActivityState::class, $quote);
        $this->assertInstanceOf(ActivityState::class, $ship);
        $this->assertNotNull($quote->race);
        $this->assertNotNull($ship->race);
        $this->assertSame(['alpha', 'beta'], array_keys($quote->race->arms));
        $this->assertSame(['gamma', 'delta'], array_keys($ship->race->arms));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_one_arm_race(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/one arm is not a race/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_arms_sharing_a_command_class(): void
    {
        // the outbox stamping matches on the command class; a shared one cannot be attributed
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: AlphaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/issue the same command class/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_arms_whose_outcomes_overlap(): void
    {
        // one arriving event would crown two winners
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap by inheritance/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_state_with_no_success_edge_to_settle_on(): void
    {
        // the settling wait is DERIVED from the success edge, so a race declared on a state that never
        // yields success has nowhere to crown a winner. The declaration pass judges the arm SET and never
        // the graph, and the assembled pass runs after the policy is built, so this refusal is the only
        // one standing between the author and a race that could not settle
        $wf = new #[Workflow(name: 'unsettlable', globalTimeout: 3600)]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'gave_up', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: 'quote', trigger: 'failure', to: 'gave_up')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('no winner to crown');

        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_state_that_also_declares_compensate(): void
    {
        // a race compensates PER ARM; a state-level undo beside it would leave the rollback two truths
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[Compensate(state: 'quote', activity: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/two truths/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_settling_wait_that_accepts_a_foreign_event(): void
    {
        // leaving through an event no arm owns would strand every loser undisposed
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, AlphaCommand::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/foreign event/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_racing_state_carrying_a_second_success_edge(): void
    {
        // the settling wait is derived statically from the FIRST success edge and reads no guard,
        // while the selector answers at runtime with the first edge whose guard PASSES: a second one
        // lets the state arm one wait and leave through another. Refused where it is written, since
        // no runtime coordination can repair an ambiguity the topology carries.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'elsewhere', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[WaitFor(state: 'elsewhere', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await', guard: 'isSettled')]
        #[On(from: 'quote', trigger: 'success', to: 'elsewhere')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[On(from: 'elsewhere', trigger: 'event', to: 'done')]
        class
        {
            /** @param  array<string, mixed>  $vars */
            public function isSettled(array $vars): bool
            {
                return (bool) ($vars['settled'] ?? false);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/MORE THAN ONE success transition/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_joining_state_carrying_a_second_success_edge(): void
    {
        // the race rule's twin: the joining wait is derived from the same edge, by the same static
        // derivation, so the same second edge carries the same ambiguity
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'elsewhere', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[WaitFor(state: 'elsewhere', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await', guard: 'isSettled')]
        #[On(from: 'checks', trigger: 'success', to: 'elsewhere')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[On(from: 'elsewhere', trigger: 'event', to: 'done')]
        class
        {
            /** @param  array<string, mixed>  $vars */
            public function isSettled(array $vars): bool
            {
                return (bool) ($vars['settled'] ?? false);
            }
        };

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/MORE THAN ONE success transition/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_plain_state_declared_before_a_race_does_not_hide_its_foreign_event(): void
    {
        // the rule walks every state and SKIPS the ones carrying no race. A walk that ended on the
        // first skip would clear the whole definition after reading one plain activity, which is
        // what almost every real workflow declares first.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'prepare', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, AlphaCommand::class], heartbeatSeconds: 60)]
        #[On(from: 'prepare', trigger: 'success', to: 'quote')]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/foreign event/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_arm_whose_outcome_the_settling_wait_never_awaits(): void
    {
        // an arm that cannot win through the wait that settles it is a fire-and-forget wearing a racer's number
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/does not accept/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_state_reachable_through_a_cycle(): void
    {
        // a race is compensatable BY CONSTRUCTION: a revisit would duplicate its (state, arm) log pairs
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'again', type: 'activity', activity: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'again')]
        #[On(from: 'again', trigger: 'success', to: 'quote')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cycle/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function a_two_arm_join_builds_with_its_derived_joining_wait_and_resolved_arms(): void
    {
        $wf = new #[Workflow(name: 'join')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'failed', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class, failedBy: GammaFailed::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, GammaFailed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[On(from: 'await', trigger: 'event', to: 'failed', onEvent: GammaFailed::class)]
        class {};

        $def = $this->builder()->build($wf);
        $state = $def->state('checks');

        $this->assertInstanceOf(ActivityState::class, $state);
        $this->assertNotNull($state->join);
        $this->assertSame('await', $state->join->joinedBy); // DERIVED from the success edge, never declared
        $this->assertSame(['alpha', 'beta'], array_keys($state->join->arms));
        $this->assertSame(AlphaCommand::class, $state->join->arms['alpha']->command);
        $this->assertSame(GammaFailed::class, $state->join->arms['alpha']->failedBy);
        $this->assertSame(SettlementSettled::class, $state->join->arms['beta']->completedBy);
        $this->assertNull($state->join->arms['beta']->failedBy); // silence is the heartbeat's business
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_arm_named_only_with_whitespace(): void
    {
        // The guard trims before comparing, and the trim is the whole of it: an arm named "  " is
        // blank to a human and to the correlation grammar alike, but not to `=== ''`.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: '  ', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/blank/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function reports_a_numeric_state_key_carrying_join_arms_as_the_string_it_was_declared_as(): void
    {
        // The race twin, for the join rules: same silent int coercion of the array key, same cast
        // back before the state is named in a refusal.
        $wf = new #[Workflow(name: 'numeric')]
        #[State(key: '2', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[Start(state: '2')]
        #[WaitFor(state: '2', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[JoinArm(state: '2', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: '2', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: '2', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/"2"/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_arm_naming_a_command_class_that_does_not_exist(): void
    {
        // Each of an arm's declared classes is resolved at build time, the command included: a typo
        // there would otherwise surface as a dispatch failure in production, on the one path the
        // whole join exists to coordinate.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: 'Storm\Saga\Tests\Fixture\NoSuchCommand', completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/names a command class that does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_join_arm_outcomes_that_overlap_by_subtype(): void
    {
        // The overlap test is SYMMETRIC on purpose, and only a strict subtype pair proves it: with two
        // identical classes both directions hold and an `&&` would read as correct. Here the abstract
        // base subsumes the concrete one way only, so a one-directional check lets an ambiguous join
        // build; the reversed-order test below carries the other direction.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: AbstractSettlement::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [AbstractSettlement::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_join_arm_outcomes_that_overlap_by_subtype_declared_refined_first(): void
    {
        // the other is_a direction of the join loop, whose pairs are visited once by construction
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: AbstractSettlement::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [AbstractSettlement::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_two_join_arms_sharing_a_name_on_one_state(): void
    {
        // The race twin: the arm name keys the compensation log, so it has to be unique per state.
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'alpha', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/alpha/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function collects_join_arms_for_every_state_that_declares_them(): void
    {
        // The race twin, on the join map: one state's arms cannot stand in for the map.
        $wf = new #[Workflow(name: 'two_joins')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await_checks', type: 'wait')]
        #[State(key: 'settle', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await_settle', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'settle', arm: 'gamma', command: AlphaCommand::class, completedBy: GammaFailed::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'settle', arm: 'delta', command: BetaCommand::class, completedBy: SettlementConfirmed::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await_checks', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[WaitFor(state: 'await_settle', events: [GammaFailed::class, SettlementConfirmed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await_checks')]
        #[On(from: 'await_checks', trigger: 'event', to: 'settle')]
        #[On(from: 'settle', trigger: 'success', to: 'await_settle')]
        #[On(from: 'await_settle', trigger: 'event', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $checks = $def->state('checks');
        $settle = $def->state('settle');

        $this->assertInstanceOf(ActivityState::class, $checks);
        $this->assertInstanceOf(ActivityState::class, $settle);
        $this->assertNotNull($checks->join);
        $this->assertNotNull($settle->join);
        $this->assertSame(['alpha', 'beta'], array_keys($checks->join->arms));
        $this->assertSame(['gamma', 'delta'], array_keys($settle->join->arms));
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_one_arm_join(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: SampleEvent::class, heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/one arm is not a join/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_state_that_also_races(): void
    {
        // out at the first outcome or out at the last are opposite contracts
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'checks', arm: 'gamma', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'checks', arm: 'delta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/never both/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_state_that_also_declares_compensate(): void
    {
        // a join compensates PER ARM; a state-level undo beside it would leave the rollback two truths
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[Compensate(state: 'checks', activity: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/two truths/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_join_arms_sharing_a_command(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: AlphaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cannot be attributed/');
        $this->builder()->build($wf);
    }

    // the arm declarations a developer outside this repo can write, refused at build

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_arm_declared_on_a_state_that_is_not_an_activity(): void
    {
        // only an activity fans a race out; an arm hung on a wait would declare a disposition for
        // commands that state can never issue
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'await', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'await', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_race_arm_name(): void
    {
        // the name IS the outbox effect_group and the log identity: blank, nothing can be stamped,
        // recalled or disposed of by arm
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: '  ', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/blank arm name/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_arm_naming_a_class_that_does_not_exist(): void
    {
        // a typo in a command, outcome or compensation class is a build-time fact, and the message
        // names which field carries it
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: 'Storm\Saga\Tests\Fixture\AlphaCommandTypo', wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/names a command class that does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_settling_on_a_state_that_is_not_a_wait(): void
    {
        // the settling wait is DERIVED from the success edge, so pointing that edge at an activity
        // leaves the race with nowhere to observe its first outcome
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'book', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: 'quote', trigger: 'success', to: 'book')]
        #[On(from: 'book', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a wait state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_race_whose_settling_wait_declares_aliases(): void
    {
        // the winner lookup matches outcome CLASSES; an alias-declaring wait would let an outcome
        // arrive that no arm can be resolved from
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'quote', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[RaceArm(state: 'quote', arm: 'alpha', command: AlphaCommand::class, wonBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[RaceArm(state: 'quote', arm: 'beta', command: BetaCommand::class, wonBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, 'settlement.settled'], heartbeatSeconds: 60)]
        #[On(from: 'quote', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts event type aliases/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_arm_declared_on_a_state_that_is_not_an_activity(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'await', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'await', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not an activity/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_blank_join_arm_name(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: '', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/blank arm name/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_arm_naming_a_class_that_does_not_exist(): void
    {
        // failedBy is checked with the rest: an arm can only be condemned by an event that exists
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class, failedBy: 'Storm\Saga\Tests\Fixture\GammaFailedTypo')]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/names a failedBy class that does not exist/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_completing_on_a_state_that_is_not_a_wait(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'book', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: 'checks', trigger: 'success', to: 'book')]
        #[On(from: 'book', trigger: 'success', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/not a wait state/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_whose_joining_wait_declares_aliases(): void
    {
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, 'settlement.settled'], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/accepts event type aliases/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_arm_whose_completion_the_wait_never_accepts(): void
    {
        // an arm whose outcome cannot arrive through the wait that joins it can never complete nor
        // fail: the join would rest forever on an arrival that has no door
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: GammaFailed::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/does not accept/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_plain_state_declared_before_a_join_does_not_hide_its_foreign_event(): void
    {
        // the race rule's twin on the join walk, and the same skip: a definition whose first state
        // carries no join must still be read to its end
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'prepare', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, AlphaCommand::class], heartbeatSeconds: 60)]
        #[On(from: 'prepare', trigger: 'success', to: 'checks')]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/foreign event/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_budget_below_one(): void
    {
        // zero extensions is not a policy, it is a #[Retimable] that should not be there
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[Start(state: 'await')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'expired')]
        #[Retimable(state: 'await', maxRetimes: 0)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxRetimes/');
        $this->builder()->build($wf);
    }

    #[Test]
    public function collects_a_retime_policy_for_every_wait_that_declares_one(): void
    {
        // The policy map is keyed by state, and one entry cannot stand in for it: truncated, the
        // second wait would silently lose its grant and a signal aimed at it would be refused as
        // if it had never been retimable.
        $wf = new #[Workflow(name: 'two_retimables')]
        #[State(key: 'first', type: 'wait')]
        #[State(key: 'second', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[Start(state: 'first')]
        #[WaitFor(state: 'first', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'expired')]
        #[WaitFor(state: 'second', events: SettlementSettled::class, deadlineSeconds: 90, onDeadline: 'expired')]
        #[Retimable(state: 'first', maxRetimes: 2)]
        #[Retimable(state: 'second', maxExtensionSeconds: 30)]
        #[On(from: 'first', trigger: 'event', to: 'second')]
        #[On(from: 'second', trigger: 'event', to: 'done')]
        class {};

        $def = $this->builder()->build($wf);
        $first = $def->state('first');
        $second = $def->state('second');

        $this->assertInstanceOf(WaitState::class, $first);
        $this->assertInstanceOf(WaitState::class, $second);
        $this->assertSame(2, $first->retime?->maxRetimes);
        $this->assertSame(30, $second->retime?->maxExtensionSeconds);
    }

    #[Test]
    public function accepts_a_retimable_whose_caps_sit_exactly_on_their_floor(): void
    {
        // The floor twin of the two rejections around it. Without it the guards read `<= 1` just as
        // well as `< 1`, and a policy of one retime of one second, the smallest policy that IS one,
        // would be refused with nothing to say so.
        $wf = new #[Workflow(name: 'floor')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[Start(state: 'await')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'expired')]
        #[Retimable(state: 'await', maxRetimes: 1, maxExtensionSeconds: 1)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $state = $this->builder()->build($wf)->state('await');

        $this->assertInstanceOf(WaitState::class, $state);
        $this->assertNotNull($state->retime);
        $this->assertSame(1, $state->retime->maxRetimes);
        $this->assertSame(1, $state->retime->maxExtensionSeconds);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_retimable_extension_cap_below_one(): void
    {
        // a cap under a second grants an extension that expires as it is granted
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'expired', type: 'final')]
        #[Start(state: 'await')]
        #[WaitFor(state: 'await', events: SampleEvent::class, deadlineSeconds: 60, onDeadline: 'expired')]
        #[Retimable(state: 'await', maxRetimes: 2, maxExtensionSeconds: 0)]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/maxExtensionSeconds/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_completion_overlapping_another_arms_failure(): void
    {
        // one arriving event must mean exactly one thing for exactly one arm: completing alpha
        // AND condemning beta on the same delivery would be two verdicts from one fact
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class, failedBy: SampleEvent::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/overlap by inheritance/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_whose_wait_accepts_a_foreign_event(): void
    {
        // leaving through a foreign event would strand every arrival unaccounted
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, GammaFailed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/foreign event/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_an_arm_whose_failure_the_joining_wait_never_awaits(): void
    {
        // an arm whose failedBy cannot arrive through the joining wait could never dispose the join
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class, failedBy: GammaFailed::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/does not accept/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_failure_the_wait_accepts_but_never_routes(): void
    {
        // through the catch-all a failedBy would cross into the success path: a definitive refusal
        // dressed as the join's completion
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class, failedBy: GammaFailed::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class, GammaFailed::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/Route the failure/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_completion_routed_through_its_own_edge(): void
    {
        // only the LAST completion crosses: an individually routed completion would make the target
        // depend on which arm happened to arrive last
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'done', type: 'final')]
        #[State(key: 'other', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'done')]
        #[On(from: 'await', trigger: 'event', to: 'other', onEvent: SampleEvent::class)]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/arrive last/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_state_reachable_through_a_cycle(): void
    {
        // a join is compensatable BY CONSTRUCTION: a revisit would duplicate its (state, arm) log pairs
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'await', type: 'wait')]
        #[State(key: 'again', type: 'activity', activity: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[WaitFor(state: 'await', events: [SampleEvent::class, SettlementSettled::class], heartbeatSeconds: 60)]
        #[On(from: 'checks', trigger: 'success', to: 'await')]
        #[On(from: 'await', trigger: 'event', to: 'again')]
        #[On(from: 'again', trigger: 'success', to: 'checks')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/cycle/');
        $this->builder()->build($wf);
    }

    #[Test]
    #[Group('adversarial')]
    public function rejects_a_join_without_a_success_edge(): void
    {
        // the joining wait is DERIVED from the success edge; a join with nowhere to complete has nothing to wait on
        $wf = new #[Workflow(name: 'bad')]
        #[State(key: 'checks', type: 'activity', activity: RecordingActivity::class)]
        #[State(key: 'done', type: 'final')]
        #[JoinArm(state: 'checks', arm: 'alpha', command: AlphaCommand::class, completedBy: SampleEvent::class, compensate: RecordingActivity::class)]
        #[JoinArm(state: 'checks', arm: 'beta', command: BetaCommand::class, completedBy: SettlementSettled::class, compensate: RecordingActivity::class)]
        #[On(from: 'checks', trigger: 'failure', to: 'done')]
        class {};

        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageMatches('/nowhere to complete/');
        $this->builder()->build($wf);
    }
}
