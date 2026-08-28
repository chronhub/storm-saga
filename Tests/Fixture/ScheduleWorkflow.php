<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Schedule;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * A representative schedule `#[Workflow]` for the builder tests, a loan-installment schedule:
 * - A schedule state on a monthly cron
 * - A bounded catch-up, replaying up to 3 missed installments, with an idempotent tick
 * - The `schedule` edge to the charge activity
 * - A guarded exit when the loan is paid off
 */
#[Workflow(name: 'loan_schedule')]
#[Start(state: 'await_installment')]
#[State(key: 'await_installment', type: 'schedule')]
#[State(key: 'charge', type: 'activity', activity: RecordingActivity::class)]
#[State(key: 'settled', type: 'final')]
#[Schedule(state: 'await_installment', cron: '0 0 1 * *', catchUp: 'bounded', catchUpLimit: 3, idempotentTick: true)]
#[On(from: 'await_installment', trigger: 'schedule', to: 'charge')]
#[On(from: 'charge', trigger: 'success', to: 'settled', guard: 'isPaidOff')]
#[On(from: 'charge', trigger: 'success', to: 'await_installment')]
final class ScheduleWorkflow
{
    /**
     * @param  array<string, mixed>  $vars
     */
    public function isPaidOff(array $vars): bool
    {
        return (int) ($vars['remaining'] ?? 1) <= 0;
    }
}
