<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Engine\State\FinalRunner;
use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\Stimulus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\WorkflowDefinition;

final class FinalRunnerTest extends TestCase
{
    #[Test]
    public function completes_the_workflow_keeping_the_vars(): void
    {
        $state = new FinalState('done');
        $ctx = new StateContext('wf', 'corr-1', new WorkflowDefinition('wf', ['done' => $state], 'done'), $state, Stimulus::none(), PointInTime::from('2026-01-01T00:00:00.000000Z'), anchor: PointInTime::from('2026-01-01T00:00:00.000000Z'), vars: ['total' => 42]);

        $verdict = new FinalRunner()->run($ctx);

        $this->assertSame(['total' => 42], $verdict->vars);
    }
}
