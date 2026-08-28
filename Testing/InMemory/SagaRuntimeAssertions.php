<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use PHPUnit\Framework\Assert;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;

/**
 * PHPUnit assertions over the in-memory runtime, the four judgements every workflow test repeats:
 * where the saga rests, the final state it completed at, the sealed trail it issued in order, and
 * the take-and-publish move on one pending command.
 *
 * Excluded from the unit mutation field on purpose: no unit test can plant a saga row without
 * driving the engine, and engine lines covered from the unit suite widen that field into territory
 * only the wired drives prove. The drives and workflow tests that adopt this trait are its
 * judgement.
 *
 * Also excluded from the package CLASSMAP on purpose: PHPUnit is a `suggest`, so a
 * classmap-authoritative production dump enumerating this trait would fatal on the missing
 * dependency; PSR-4 still autoloads it where it belongs, inside a test suite.
 *
 * @infection-ignore-all
 */
trait SagaRuntimeAssertions
{
    /**
     * The saga exists and rests at the given state, whatever its status.
     */
    protected function assertSagaRestingAt(InMemorySagaRuntime $runtime, string $workflowType, string $correlationId, string $stateKey): void
    {
        Assert::assertSame($stateKey, $this->sagaOrFail($runtime, $workflowType, $correlationId)->stateKey);
    }

    /**
     * The saga completed AND came to rest at the given final state: the pair judged together, so a
     * halt parked on the right key or a completion through the wrong arm both fail.
     */
    protected function assertSagaCompletedAt(InMemorySagaRuntime $runtime, string $workflowType, string $correlationId, string $stateKey): void
    {
        $instance = $this->sagaOrFail($runtime, $workflowType, $correlationId);

        Assert::assertSame($stateKey, $instance->stateKey);
        Assert::assertSame(WorkflowStatus::Completed, $instance->status);
    }

    /**
     * The whole captured trail, every status, judged as payload classes in ISSUE order: the
     * choreography assertion, proving both what the saga issued and what it never did.
     *
     * @param  list<class-string>  $payloadClasses
     */
    protected function assertCommandTrail(InMemorySagaRuntime $runtime, array $payloadClasses): void
    {
        Assert::assertSame(
            $payloadClasses,
            array_map(static fn (CapturedCommand $command): string => $command->payload()::class, $runtime->commands()),
        );
    }

    /**
     * Takes the one pending command of the class, marks it published, and returns its payload
     * typed: the relay's move compressed to what a scenario needs between two outcomes.
     *
     * @template T of object
     *
     * @param  class-string<T>  $payloadClass
     * @return T
     */
    protected function takePublishedPayload(InMemorySagaRuntime $runtime, string $payloadClass): object
    {
        $command = $runtime->takeCommand($payloadClass);
        $runtime->markPublished($command->messageId);
        $payload = $command->payload();
        Assert::assertInstanceOf($payloadClass, $payload);

        return $payload;
    }

    private function sagaOrFail(InMemorySagaRuntime $runtime, string $workflowType, string $correlationId): WorkflowInstanceRow
    {
        $instance = $runtime->instance($workflowType, $correlationId);
        Assert::assertNotNull($instance, sprintf('No saga "%s/%s" exists in the runtime.', $workflowType, $correlationId));

        return $instance;
    }
}
