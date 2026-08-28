<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Attributes\OnTrigger;
use Storm\Saga\Engine\TimerOpKind;
use Storm\Saga\Engine\WaitEscalator;
use Storm\Saga\Event\SagaAwaitEscalated;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Tests\Fixture\SampleEvent;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\Timeout;
use Storm\Saga\Workflow\Transition;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class WaitEscalatorTest extends TestCase
{
    #[Test]
    public function a_state_deadline_re_arms_the_waits_own_timeout(): void
    {
        $effects = new WaitEscalator()->escalate($this->def(waitTimeout: 30), $this->row());

        $this->assertCount(1, $effects->timerOps);
        $this->assertSame(TimerOpKind::ArmTimeout, $effects->timerOps[0]->kind);
        $this->assertSame('await', $effects->timerOps[0]->stateKey);
        $this->assertSame(30, $effects->timerOps[0]->seconds);

        $this->assertCount(1, $effects->announcements);
        $this->assertInstanceOf(SagaAwaitEscalated::class, $effects->announcements[0]);
        $this->assertSame('await', $effects->announcements[0]->stateKey);
    }

    #[Test]
    public function a_wait_without_its_own_timeout_still_announces_without_re_arming(): void
    {
        // a definition drift can leave a gating wait untimed; keep watch, never finalize
        $effects = new WaitEscalator()->escalate($this->def(waitTimeout: null), $this->row());

        $this->assertSame([], $effects->timerOps);
        $this->assertCount(1, $effects->announcements);
    }

    private function def(?int $waitTimeout): WorkflowDefinition
    {
        $await = new WaitState('await', eventClasses: [SampleEvent::class], timeout: $waitTimeout === null ? null : new Timeout($waitTimeout), transitions: [new Transition(OnTrigger::Event, 'done')]);

        return new WorkflowDefinition('wf', ['await' => $await, 'done' => new FinalState('done')], 'await');
    }

    private function row(): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('wf', 'c-1', 'await', WorkflowStatus::Running, startedAt: new PointInTime);
    }
}
