<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class WorkflowRegistryTest extends TestCase
{
    #[Test]
    public function resolves_a_registered_workflow_by_name(): void
    {
        $payment = $this->def('payment');

        $registry = new WorkflowRegistry([$payment]);

        $this->assertTrue($registry->has('payment'));
        $this->assertSame($payment, $registry->get('payment'));
    }

    #[Test]
    public function resolves_the_latest_version_when_no_version_is_given(): void
    {
        $v1 = $this->def('transfer', 1);
        $v2 = $this->def('transfer', 2);

        $registry = new WorkflowRegistry([$v1, $v2]);

        $this->assertSame($v2, $registry->get('transfer'));
    }

    #[Test]
    public function resolves_a_specific_pinned_version(): void
    {
        $v1 = $this->def('transfer', 1);
        $v2 = $this->def('transfer', 2);

        $registry = new WorkflowRegistry([$v1, $v2]);

        $this->assertSame($v1, $registry->get('transfer', 1));
        $this->assertSame($v2, $registry->get('transfer', 2));
    }

    #[Test]
    public function latest_version_is_the_highest_registered(): void
    {
        // out-of-order registration; latest is by value, not insertion
        $registry = new WorkflowRegistry([$this->def('transfer', 3), $this->def('transfer', 1)]);

        $this->assertSame(3, $registry->latestVersion('transfer'));
    }

    #[Test]
    public function has_distinguishes_name_and_version(): void
    {
        $registry = new WorkflowRegistry([$this->def('transfer', 2)]);

        $this->assertTrue($registry->has('transfer'));
        $this->assertTrue($registry->has('transfer', 2));
        $this->assertFalse($registry->has('transfer', 1));
        $this->assertFalse($registry->has('nope'));
    }

    #[Test]
    public function versions_lists_every_version_of_a_name(): void
    {
        $v1 = $this->def('transfer', 1);
        $v2 = $this->def('transfer', 2);

        $registry = new WorkflowRegistry([$v1, $v2]);

        $this->assertSame([1 => $v1, 2 => $v2], $registry->versions('transfer'));
    }

    #[Test]
    public function versions_throws_for_an_unknown_workflow(): void
    {
        $this->expectException(WorkflowNotFound::class);
        (new WorkflowRegistry)->versions('nope');
    }

    #[Test]
    public function all_flattens_every_name_and_version(): void
    {
        $payment = $this->def('payment');
        $v1 = $this->def('transfer', 1);
        $v2 = $this->def('transfer', 2);

        $registry = new WorkflowRegistry([$payment, $v1, $v2]);

        $this->assertSame([$payment, $v1, $v2], $registry->all());
    }

    #[Test]
    public function throws_for_an_unknown_workflow(): void
    {
        $registry = new WorkflowRegistry;

        $this->assertFalse($registry->has('nope'));

        $this->expectException(WorkflowNotFound::class);
        $this->expectExceptionMessageIsOrContains('No workflow registered under "nope".');
        $registry->get('nope');
    }

    #[Test]
    public function throws_for_an_unknown_version(): void
    {
        $registry = new WorkflowRegistry([$this->def('transfer', 1)]);

        $this->expectException(WorkflowVersionNotFound::class);
        $this->expectExceptionMessageIsOrContains('Workflow "transfer" has no registered version 2');
        $registry->get('transfer', 2);
    }

    #[Test]
    public function latest_version_throws_for_an_unknown_workflow(): void
    {
        $registry = new WorkflowRegistry;

        $this->expectException(WorkflowNotFound::class);
        $registry->latestVersion('nope');
    }

    #[Test]
    public function rejects_a_duplicate_name_version_pair(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('Two #[Workflow] classes declare name "transfer" at version 1');

        new WorkflowRegistry([$this->def('transfer', 1), $this->def('transfer', 1)]);
    }

    #[Test]
    public function rejects_a_version_below_one(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('version must be >= 1');

        new WorkflowRegistry([$this->def('transfer', 0)]);
    }

    #[Test]
    public function rejects_a_duplicate_label_within_a_name(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('Two versions of workflow "transfer" share label "fraud_check"');

        new WorkflowRegistry([
            $this->def('transfer', 1, 'fraud_check'),
            $this->def('transfer', 2, 'fraud_check'),
        ]);
    }

    #[Test]
    public function allows_the_same_label_across_different_names(): void
    {
        $registry = new WorkflowRegistry([
            $this->def('transfer', 1, 'shared'),
            $this->def('payment', 1, 'shared'),
        ]);

        $this->assertSame('shared', $registry->get('transfer')->label);
        $this->assertSame('shared', $registry->get('payment')->label);
    }

    #[Test]
    public function rejects_a_state_version_below_one(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('stateVersion must be >= 1');

        new WorkflowRegistry([$this->def('transfer', 1, stateVersion: 0)]);
    }

    #[Test]
    public function rejects_co_registered_versions_that_disagree_on_state_version(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('Co-registered versions of workflow "transfer" disagree on stateVersion: 2 declared, but version 2 declares 3');

        new WorkflowRegistry([
            $this->def('transfer', 1, stateVersion: 2),
            $this->def('transfer', 2, stateVersion: 3),
        ]);
    }

    #[Test]
    public function co_registered_versions_share_one_state_version(): void
    {
        $registry = new WorkflowRegistry([
            $this->def('transfer', 1, stateVersion: 2),
            $this->def('transfer', 2, stateVersion: 2),
        ]);

        $this->assertSame(2, $registry->get('transfer', 1)->stateVersion);
        $this->assertSame(2, $registry->get('transfer', 2)->stateVersion);
    }

    #[Test]
    public function rejects_co_registered_versions_that_disagree_on_the_exposure_allowlist(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('disagree on #[ExposesState]: version 2 declares a different list');

        new WorkflowRegistry([
            $this->def('transfer', 1, exposed: ['status']),
            $this->def('transfer', 2, exposed: ['status', 'amount']),
        ]);
    }

    #[Test]
    public function co_registered_versions_share_one_exposure_allowlist(): void
    {
        $registry = new WorkflowRegistry([
            $this->def('transfer', 1, exposed: ['status']),
            $this->def('transfer', 2, exposed: ['status']),
        ]);

        $this->assertSame(['status'], $registry->get('transfer', 1)->exposedStateKeys);
        $this->assertSame(['status'], $registry->get('transfer', 2)->exposedStateKeys);
    }

    #[Test]
    public function state_versions_are_independent_across_names(): void
    {
        $registry = new WorkflowRegistry([
            $this->def('transfer', 1, stateVersion: 2),
            $this->def('payment', 1, stateVersion: 5),
        ]);

        $this->assertSame(2, $registry->get('transfer')->stateVersion);
        $this->assertSame(5, $registry->get('payment')->stateVersion);
    }

    /**
     * @param  list<string>  $exposed
     */
    private function def(string $name, int $version = 1, ?string $label = null, int $stateVersion = 1, array $exposed = []): WorkflowDefinition
    {
        return new WorkflowDefinition($name, ['done' => new FinalState], 'done', version: $version, label: $label, stateVersion: $stateVersion, exposedStateKeys: $exposed);
    }
}
