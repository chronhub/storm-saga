<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Closure;
use Storm\Saga\Attributes\On;
use Storm\Saga\Attributes\Start;
use Storm\Saga\Attributes\State;
use Storm\Saga\Attributes\Workflow;

/**
 * A workflow whose guard reads a closure the INSTANCE holds, the shape that makes an assembled
 * definition unserializable.
 *
 * Guard closures bind to the instance, so the definition reaches whatever the instance carries; a
 * captured `Closure` is the cheapest honest stand-in for the connection handles and callables a real
 * workflow holds. It is the reason a disk cache of assembled definitions is not available, and
 * therefore the reason on-demand assembly is the lever that is.
 */
#[Workflow(name: 'capturing_guard')]
#[Start(state: 'run')]
#[State(key: 'run', type: 'activity', activity: RecordingActivity::class)]
#[State(key: 'done', type: 'final')]
#[State(key: 'refused', type: 'final')]
#[On(from: 'run', trigger: 'success', to: 'done', guard: 'isAllowed')]
#[On(from: 'run', trigger: 'failure', to: 'refused')]
final readonly class CapturingGuardWorkflow
{
    /** @var Closure(array<string, mixed>): bool */
    private Closure $policy;

    public function __construct()
    {
        $this->policy = static fn (array $vars): bool => ($vars['amount'] ?? 0) > 10;
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public function isAllowed(array $vars): bool
    {
        return ($this->policy)($vars);
    }
}
