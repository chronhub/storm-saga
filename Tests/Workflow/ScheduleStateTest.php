<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Workflow\Cadence\CronCadence;
use Storm\Saga\Workflow\Cadence\IntervalCadence;
use Storm\Saga\Workflow\CatchUp;
use Storm\Saga\Workflow\CatchUpPolicy;
use Storm\Saga\Workflow\ScheduleState;

final class ScheduleStateTest extends TestCase
{
    #[Test]
    public function returns_the_fixed_cadence_unchanged_when_one_is_set(): void
    {
        $cadence = new IntervalCadence(3600);
        $state = new ScheduleState('tick', $cadence, new CatchUpPolicy(CatchUp::Skip));

        $this->assertSame($cadence, $state->cadenceFor(['anything' => 1])); // vars ignored for a fixed cadence
    }

    #[Test]
    public function resolves_a_per_instance_cron_from_vars_at_the_tick(): void
    {
        // the customer's own billing day, carried in vars
        $state = new ScheduleState('tick', null, new CatchUpPolicy(CatchUp::Skip), [], static fn (array $vars): string => $vars['billing_cron']);

        $cadence = $state->cadenceFor(['billing_cron' => '0 0 15 * *']);

        $this->assertInstanceOf(CronCadence::class, $cadence);
        $next = $cadence->nextFireAfter(PointInTime::from('2026-06-01T00:00:00.000000Z'), PointInTime::from('2026-06-01T00:00:00.000000Z'));
        $this->assertStringContainsString('2026-06-15', $next->toString()); // the 15th, per this instance
    }

    #[Test]
    public function a_per_instance_cron_resolved_to_an_invalid_expression_fails_loud(): void
    {
        $state = new ScheduleState('tick', null, new CatchUpPolicy(CatchUp::Skip), [], static fn (array $vars): string => 'not a cron');

        $this->expectException(InvalidResolvedCron::class);
        $this->expectExceptionMessageIsOrContains('resolved a per-instance cron to "not a cron"');
        $state->cadenceFor([]);
    }

    #[Test]
    public function neither_a_fixed_cadence_nor_a_resolver_is_a_logic_error(): void
    {
        $state = new ScheduleState('tick', null, new CatchUpPolicy(CatchUp::Skip));

        $this->expectException(LogicException::class);
        $state->cadenceFor([]);
    }
}
