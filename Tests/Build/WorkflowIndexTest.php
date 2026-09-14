<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowIndex;
use Storm\Saga\Build\WorkflowMetadata;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Tests\Fixture\CapturingGuardWorkflow;
use Storm\Saga\Tests\Fixture\PaymentWorkflow;

/**
 * The index judged on the one thing that distinguishes it: it decides over declarations, never over
 * assembled graphs.
 *
 * Every law here is a comparison BETWEEN declarations, which is why a registry that assembles on
 * demand can still enforce them at construction. The suite therefore feeds it metadata directly and
 * asserts the refusal falls with no builder, no container and no workflow instance in sight.
 */
final class WorkflowIndexTest extends TestCase
{
    #[Test]
    public function reads_a_declaration_off_a_class_without_instantiating_it(): void
    {
        $metadata = WorkflowMetadata::fromClass(PaymentWorkflow::class);

        self::assertSame('payment', $metadata->name);
        self::assertSame(1, $metadata->version);
        self::assertSame(1, $metadata->stateVersion);
        self::assertSame('payment:1', $metadata->key());
    }

    #[Test]
    public function refuses_a_class_that_declares_no_workflow(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('is not a workflow');

        WorkflowMetadata::fromClass(WorkflowIndexTest::class);
    }

    #[Test]
    public function answers_presence_and_the_latest_version_without_any_graph(): void
    {
        $index = new WorkflowIndex([$this->meta('transfer', 1), $this->meta('transfer', 3), $this->meta('payment')]);

        self::assertTrue($index->has('transfer'));
        self::assertTrue($index->has('transfer', 3));
        self::assertFalse($index->has('transfer', 2));
        self::assertFalse($index->has('nope'));
        self::assertSame(3, $index->latestVersion('transfer'));
        self::assertSame([1, 3], $index->versionsOf('transfer'));
        self::assertSame(['transfer' => [1, 3], 'payment' => [1]], $index->declared());
    }

    #[Test]
    public function an_unknown_name_has_neither_a_latest_version_nor_a_version_list(): void
    {
        $index = new WorkflowIndex;

        self::assertSame([], $index->declared());
        self::assertNull($index->metadata('nope', 1));

        $this->expectException(WorkflowNotFound::class);
        $index->latestVersion('nope');
    }

    #[Test]
    public function rejects_a_duplicate_name_version_pair(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('a (name, version) pair is unique');

        new WorkflowIndex([$this->meta('transfer', 2), $this->meta('transfer', 2)]);
    }

    #[Test]
    public function rejects_a_duplicate_label_within_one_name(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('share label "audited"');

        new WorkflowIndex([$this->meta('transfer', 1, 'audited'), $this->meta('transfer', 2, 'audited')]);
    }

    #[Test]
    public function allows_one_label_across_two_names(): void
    {
        $index = new WorkflowIndex([$this->meta('transfer', 1, 'audited'), $this->meta('payment', 1, 'audited')]);

        self::assertSame(['transfer' => [1], 'payment' => [1]], $index->declared());
    }

    #[Test]
    public function rejects_co_registered_versions_that_disagree_on_state_version(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('disagree on stateVersion');

        new WorkflowIndex([$this->meta('transfer', 1, stateVersion: 1), $this->meta('transfer', 2, stateVersion: 2)]);
    }

    #[Test]
    public function rejects_co_registered_versions_that_disagree_on_the_exposure_allowlist(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('disagree on #[ExposesState]');

        new WorkflowIndex([$this->meta('transfer', 1, exposed: ['amount']), $this->meta('transfer', 2, exposed: ['amount', 'iban'])]);
    }

    #[Test]
    public function rejects_a_version_below_one(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('version must be >= 1');

        new WorkflowIndex([$this->meta('transfer', 0)]);
    }

    #[Test]
    public function rejects_a_state_version_below_one(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('stateVersion must be >= 1');

        new WorkflowIndex([$this->meta('transfer', 1, stateVersion: 0)]);
    }

    #[Test]
    public function two_names_keep_independent_state_versions(): void
    {
        $index = new WorkflowIndex([$this->meta('transfer', 1, stateVersion: 2), $this->meta('payment', 1, stateVersion: 5)]);

        self::assertSame(2, $index->metadata('transfer', 1)?->stateVersion);
        self::assertSame(5, $index->metadata('payment', 1)?->stateVersion);
    }

    #[Test]
    public function a_capturing_workflow_declares_like_any_other(): void
    {
        // the instance holds a closure, which stops nothing at the declaration level: only the
        // assembled graph is unserializable, and the index never assembles one
        $metadata = WorkflowMetadata::fromClass(CapturingGuardWorkflow::class);

        self::assertSame('capturing_guard:1', $metadata->key());
    }

    /**
     * @param  list<string>  $exposed
     */
    private function meta(string $name, int $version = 1, ?string $label = null, int $stateVersion = 1, array $exposed = []): WorkflowMetadata
    {
        return new WorkflowMetadata($name, $version, $label, $stateVersion, $exposed);
    }
}
