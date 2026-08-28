<?php

declare(strict_types=1);

namespace Storm\Saga\Priority;

use Storm\Saga\Outbox\SagaLanePolicy;

/**
 * The configured `SagaLanePolicy`: resolves a command's priority level via a `PriorityResolver` and maps
 * it to a lane through the app's level-to-transport table. A level with no mapped lane, or a command with
 * no resolved priority, yields null. Storm owns neither the levels nor the lanes; both come from app
 * config, and this only joins them.
 */
final readonly class PriorityLanePolicy implements SagaLanePolicy
{
    /**
     * @param  array<int, string>  $lanes  priority level => transport; an unlisted level means no lane
     */
    public function __construct(
        private PriorityResolver $resolver,
        private array $lanes = [],
    ) {}

    public function laneFor(object $command, string $workflowType): ?string
    {
        $level = $this->resolver->resolve($command, $workflowType);

        return $level === null ? null : ($this->lanes[$level] ?? null);
    }
}
