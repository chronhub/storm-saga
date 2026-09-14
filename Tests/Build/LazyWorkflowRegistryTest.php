<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowIndex;
use Storm\Saga\Build\WorkflowMetadata;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Tests\Fixture\AuditActivity;
use Storm\Saga\Tests\Fixture\AuditWorkflow;
use Storm\Saga\Tests\Fixture\AuditWorkflowV2;
use Storm\Saga\Tests\Fixture\BrokenTransitionWorkflow;
use Storm\Saga\Tests\Fixture\CapturingGuardWorkflow;
use Storm\Saga\Tests\Fixture\CountingContainer;
use Storm\Saga\Tests\Fixture\PaymentWorkflow;
use Storm\Saga\Tests\Fixture\RecordingActivity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\ActivityState;

/**
 * The lazy regime judged on what it does NOT do.
 *
 * A registry that assembles on demand is only worth its complexity if a targeted resolution leaves
 * the rest of the catalogue untouched, so almost every assertion here reads a resolution LOG and
 * checks for absences: the workflow class that was never instantiated, the activity that was never
 * located, the second assembly that never happened. A test that only proved `get()` returns the right
 * definition would pass just as well against the eager registry and prove nothing about this one.
 *
 * The laws that compare declarations still fall at construction; they are the index's own suite. What
 * is pinned here is the split: presence and version questions answer from the table, graphs are built
 * one at a time, and the per-graph verdict lands at the first resolution of that graph alone.
 */
final class LazyWorkflowRegistryTest extends TestCase
{
    #[Test]
    public function building_the_registry_instantiates_no_workflow_and_no_activity(): void
    {
        [$registry, $workflows, $activities] = $this->registry();

        self::assertSame([], $workflows->instantiated());
        self::assertSame([], $activities->instantiated());
        self::assertSame(['audit' => [1, 2], 'payment' => [1]], $registry->declared());
    }

    #[Test]
    public function presence_and_version_questions_assemble_nothing(): void
    {
        [$registry, $workflows, $activities] = $this->registry();

        self::assertTrue($registry->has('audit'));
        self::assertTrue($registry->has('audit', 2));
        self::assertFalse($registry->has('audit', 7));
        self::assertFalse($registry->has('unknown'));
        self::assertSame(2, $registry->latestVersion('audit'));

        self::assertSame([], $workflows->resolved());
        self::assertSame([], $activities->resolved());
    }

    #[Test]
    public function resolving_one_workflow_touches_neither_the_others_nor_their_activities(): void
    {
        [$registry, $workflows, $activities] = $this->registry();

        $definition = $registry->get('audit', 1);

        self::assertSame('audit', $definition->name);
        self::assertSame(1, $definition->version);

        // the whole point: one key located, one activity located, and the catalogue untouched
        self::assertSame(['audit:1'], $workflows->instantiated());
        self::assertSame([AuditActivity::class], $activities->instantiated());
        self::assertNotContains('audit:2', $workflows->instantiated());
        self::assertNotContains('payment:1', $workflows->instantiated());
        self::assertNotContains(RecordingActivity::class, $activities->instantiated());
    }

    #[Test]
    public function the_current_version_is_the_highest_declared_one(): void
    {
        [$registry, $workflows] = $this->registry();

        $definition = $registry->get('audit');

        self::assertSame(2, $definition->version);
        self::assertSame(['audit:2'], $workflows->instantiated());
    }

    #[Test]
    public function a_second_resolution_is_served_from_memory(): void
    {
        [$registry, $workflows, $activities] = $this->registry();

        $first = $registry->get('audit');
        $second = $registry->get('audit');
        $pinned = $registry->get('audit', 2);

        self::assertSame($first, $second);
        self::assertSame($first, $pinned);

        // one location for three resolutions: a resident worker assembles a graph once and never
        // again, so the second and third calls cost a memory read and reach no container at all
        self::assertSame(['audit:2'], $workflows->resolved());
        self::assertSame([AuditActivity::class], $activities->instantiated());
    }

    #[Test]
    public function a_pinned_version_and_the_current_one_are_two_distinct_graphs(): void
    {
        [$registry] = $this->registry();

        $pinned = $registry->get('audit', 1);
        $current = $registry->get('audit');

        self::assertNotSame($pinned, $current);
        self::assertSame(['review', 'done'], array_keys($pinned->states()));
        self::assertSame(['review', 'escalate', 'done'], array_keys($current->states()));
    }

    #[Test]
    public function versions_resolves_every_version_of_one_name_and_nothing_else(): void
    {
        [$registry, $workflows] = $this->registry();

        $versions = $registry->versions('audit');

        self::assertSame([1, 2], array_keys($versions));
        self::assertSame(['audit:1', 'audit:2'], $workflows->instantiated());
        self::assertNotContains('payment:1', $workflows->instantiated());
    }

    #[Test]
    public function all_resolves_the_whole_catalogue(): void
    {
        [$registry, $workflows] = $this->registry();

        $all = $registry->all();

        self::assertCount(3, $all);
        self::assertSame(['audit:1', 'audit:2', 'payment:1'], $workflows->instantiated());
    }

    #[Test]
    public function an_unknown_name_refuses_before_locating_anything(): void
    {
        [$registry, $workflows] = $this->registry();

        try {
            $registry->get('nope');
            self::fail('an unregistered name must refuse');
        } catch (WorkflowNotFound $e) {
            self::assertStringContainsString('No workflow registered under "nope".', $e->getMessage());
        }

        self::assertSame([], $workflows->resolved());
    }

    #[Test]
    public function an_unknown_version_of_a_known_name_refuses_before_locating_anything(): void
    {
        [$registry, $workflows] = $this->registry();

        try {
            $registry->get('audit', 9);
            self::fail('an unregistered version must refuse');
        } catch (WorkflowVersionNotFound $e) {
            self::assertStringContainsString('has no registered version 9', $e->getMessage());
        }

        self::assertSame([], $workflows->resolved());
    }

    #[Test]
    public function a_malformed_declaration_refuses_only_when_its_own_graph_is_assembled(): void
    {
        $workflows = new CountingContainer([
            'audit:1' => static fn (): AuditWorkflow => new AuditWorkflow,
            'broken_transition:1' => static fn (): BrokenTransitionWorkflow => new BrokenTransitionWorkflow,
        ]);
        $registry = WorkflowRegistry::lazy(
            new WorkflowIndex([
                new WorkflowMetadata('audit', 1, 'first_pass', 1, ['reference']),
                new WorkflowMetadata('broken_transition', 1, null, 1, []),
            ]),
            $workflows,
            new WorkflowBuilder($this->activities()),
        );

        // the fault is contained to its own name: a poison declaration denies its own saga a
        // definition and leaves every healthy neighbour resolvable
        self::assertSame('audit', $registry->get('audit', 1)->name);

        $this->expectException(InvalidWorkflowDefinition::class);
        $registry->get('broken_transition');
    }

    #[Test]
    public function a_definition_holding_a_captured_closure_assembles_and_stays_unserializable(): void
    {
        $workflows = new CountingContainer([
            'capturing_guard:1' => static fn (): CapturingGuardWorkflow => new CapturingGuardWorkflow,
        ]);
        $registry = WorkflowRegistry::lazy(
            new WorkflowIndex([new WorkflowMetadata('capturing_guard', 1, null, 1, [])]),
            $workflows,
            new WorkflowBuilder($this->activities()),
        );

        $definition = $registry->get('capturing_guard');
        $run = $definition->state('run');
        self::assertInstanceOf(ActivityState::class, $run);

        $guard = $run->transitions[0]->guard;
        self::assertNotNull($guard);
        self::assertTrue($guard(['amount' => 50]));
        self::assertFalse($guard(['amount' => 1]));

        // the DSL survives intact, and that is exactly why no disk cache can stand in for on-demand
        // assembly: the assembled graph holds closures bound to the instance
        $this->expectException(Exception::class);
        $this->expectExceptionMessageIsOrContains("Serialization of 'Closure' is not allowed");
        serialize($definition);
    }

    /**
     * @return array{WorkflowRegistry, CountingContainer, CountingContainer}
     */
    private function registry(): array
    {
        $workflows = new CountingContainer([
            'audit:1' => static fn (): AuditWorkflow => new AuditWorkflow,
            'audit:2' => static fn (): AuditWorkflowV2 => new AuditWorkflowV2,
            'payment:1' => static fn (): PaymentWorkflow => new PaymentWorkflow,
        ]);
        $activities = $this->activities();

        $registry = WorkflowRegistry::lazy(
            new WorkflowIndex([
                new WorkflowMetadata('audit', 1, 'first_pass', 1, ['reference']),
                new WorkflowMetadata('audit', 2, 'second_pass', 1, ['reference']),
                new WorkflowMetadata('payment', 1, null, 1, []),
            ]),
            $workflows,
            new WorkflowBuilder($activities),
        );

        return [$registry, $workflows, $activities];
    }

    private function activities(): CountingContainer
    {
        return new CountingContainer([
            AuditActivity::class => static fn (): AuditActivity => new AuditActivity,
            RecordingActivity::class => static fn (): RecordingActivity => new RecordingActivity(ActivityResult::success([])),
        ]);
    }
}
