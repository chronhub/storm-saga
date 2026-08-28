<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Plan;

/**
 * Create a fresh instance at the workflow's start state and drive it. The performer also arms the
 * global-deadline timer when the workflow declares one and the start-drive leaves the saga Running.
 */
final readonly class StartInstance implements StepPlan
{
    /**
     * @param  array<string, mixed>  $vars  initial state bag
     * @param  array<string, mixed>  $context  ambient context handed to activities, such as actor or origin
     */
    public function __construct(
        public array $vars = [],
        public array $context = [],
    ) {}
}
