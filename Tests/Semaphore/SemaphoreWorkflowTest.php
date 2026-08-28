<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Semaphore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Semaphore\Command\GrantSlot;
use Storm\Saga\Semaphore\Reply\Granted;
use Storm\Saga\Semaphore\SemaphoreClient;
use Storm\Saga\Semaphore\SemaphoreLedger;
use Storm\Saga\Semaphore\SemaphoreWorkflow;
use Storm\Saga\Semaphore\Signal\Acquire;
use Storm\Saga\Semaphore\Signal\Release;
use Storm\Saga\Semaphore\SweepActivity;
use Storm\Saga\Tests\Fixture\ArrayContainer;
use Storm\Saga\Tests\Fixture\MutableClock;
use Storm\Saga\Workflow\Metadata;
use Storm\Saga\Workflow\ScheduleState;

final class SemaphoreWorkflowTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableClock;
    }

    /**
     * @return array<string, mixed>
     */
    private function vars(int $capacity = 1): array
    {
        return SemaphoreLedger::provisionVars('rail:visa', $capacity, 60, 4, 120, 30);
    }

    #[Test]
    public function the_shipped_definition_builds_a_schedule_guard_with_the_four_verbs(): void
    {
        $builder = new WorkflowBuilder(new ArrayContainer([
            SweepActivity::class => new SweepActivity($this->clock),
        ]));

        $definition = $builder->build(new SemaphoreWorkflow($this->clock));

        $this->assertSame(SemaphoreClient::WORKFLOW_TYPE, $definition->name);
        $this->assertSame('guard', $definition->start);
        $this->assertNull($definition->globalTimeout); // a semaphore is perpetual, the cap would kill the guardian
        $this->assertInstanceOf(ScheduleState::class, $definition->states()['guard']);
        $this->assertNotNull($definition->signalHandlerFor(new Acquire('payment', 'p-1')));
        $this->assertNotNull($definition->signalHandlerFor(new Release('payment', 'p-1')));
    }

    #[Test]
    public function on_acquire_answers_and_persists_in_the_same_run(): void
    {
        $workflow = new SemaphoreWorkflow($this->clock);

        $result = $workflow->onAcquire(new Acquire('payment', 'p-1'), $this->vars());

        $this->assertInstanceOf(Granted::class, $result->result);
        $this->assertCount(1, $result->vars[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $result->commands);
    }

    #[Test]
    public function on_release_rides_the_promotion_wake_up_as_a_command(): void
    {
        $workflow = new SemaphoreWorkflow($this->clock);
        $vars = $workflow->onAcquire(new Acquire('payment', 'p-1'), $this->vars())->vars;
        $vars = $workflow->onAcquire(new Acquire('payment', 'p-2'), $vars)->vars;

        $result = $workflow->onRelease(new Release('payment', 'p-1'), $vars);

        $this->assertCount(1, $result->commands);
        $this->assertInstanceOf(GrantSlot::class, $result->commands[0]);
        $this->assertSame('p-2', $result->commands[0]->waiterCorrelation);
    }

    #[Test]
    public function the_sweep_is_an_open_of_the_ledger_and_nothing_else(): void
    {
        $vars = $this->vars();
        $vars[SemaphoreLedger::HOLDERS] = [
            'payment'."\x1f".'p-dead' => [
                'waiter_type' => 'payment',
                'waiter_corr' => 'p-dead',
                'expires_at' => $this->clock->now->subSeconds(1)->toString(),
            ],
        ];

        $result = new SweepActivity($this->clock)->run($vars, new Metadata(SemaphoreClient::WORKFLOW_TYPE, 'rail:visa'));

        $this->assertSame(1, $result->vars[SemaphoreLedger::EXPROPRIATED]);
        $this->assertSame([], $result->vars[SemaphoreLedger::HOLDERS]);
    }
}
