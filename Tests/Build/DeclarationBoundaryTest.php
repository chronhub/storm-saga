<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\Saga\Attributes\Retimable;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Build\DeclarationReader;
use Storm\Saga\Build\WorkflowMetadata;
use Storm\Saga\Exception\InvalidWorkflowDefinition;

final class DeclarationBoundaryTest extends TestCase
{
    #[Test]
    public function a_manually_built_retimable_wait_still_requires_a_timeout_transition(): void
    {
        $wait = new \Storm\Saga\Workflow\WaitState('await', timeout: new \Storm\Saga\Workflow\Timeout(60), retime: new \Storm\Saga\Workflow\RetimePolicy(maxRetimes: 1));
        $this->expectException(InvalidWorkflowDefinition::class);
        new \Storm\Saga\Build\Rules\TimeRules()->retimedWaitsCarryTheirOwnDeadline('manual', ['await' => $wait]);
    }

    #[Test]
    public function a_downstream_cycle_is_accepted_until_it_revisits_the_compensatable_state(): void
    {
        $activity = new \Storm\Saga\Tests\Fixture\RecordingActivity(\Storm\Saga\Workflow\ActivityResult::success());
        $charge = new \Storm\Saga\Workflow\ActivityState('charge', $activity, compensation: $activity, transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Success, 'await')]);
        $await = new \Storm\Saga\Workflow\WaitState('await', transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Event, 'await')]);
        $rules = new \Storm\Saga\Build\Rules\ReachabilityRules;
        $rules->compensatableStatesAvoidCycles('downstream', ['charge' => $charge, 'await' => $await]);
        $charge = new \Storm\Saga\Workflow\ActivityState('charge', $activity, compensation: $activity, transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Success, 'returns'), new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Failure, 'await')]);
        $returnsToCharge = new \Storm\Saga\Workflow\WaitState('returns', transitions: [new \Storm\Saga\Workflow\Transition(\Storm\Saga\Attributes\OnTrigger::Event, 'charge')]);
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('charge');
        $rules->compensatableStatesAvoidCycles('downstream', ['charge' => $charge, 'await' => $await, 'returns' => $returnsToCharge]);
    }

    #[Test]
    public function a_child_settle_diagnostic_without_declared_slots_has_no_empty_suffix(): void
    {
        $error = \Storm\Saga\Exception\ChildrenStillRunning::atNominalSettle('parent', 'correlation', 2);
        $this->assertStringContainsString('2 living child', $error->getMessage());
        $this->assertStringNotContainsString('Declared:', $error->getMessage());
    }

    #[Test]
    public function duplicate_retimable_is_refused(): void
    {
        $declaration = new #[Retimable('await')] #[Retimable('await')] class {};
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('Retimable');
        new DeclarationReader()->retimablesByState($this->reflection($declaration), 'duplicate');
    }

    #[Test]
    public function blank_workflow_metadata_is_refused(): void
    {
        $declaration = new #[Workflow(name: '  ')] class {};
        $this->expectException(InvalidWorkflowDefinition::class);
        $this->expectExceptionMessageIsOrContains('blank');
        WorkflowMetadata::fromClass($declaration::class);
    }

    /**
     * @return ReflectionClass<object>
     */
    private function reflection(object $declaration): ReflectionClass
    {
        return new ReflectionClass($declaration);
    }
}
