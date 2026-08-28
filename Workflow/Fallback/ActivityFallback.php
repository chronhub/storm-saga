<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Fallback;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * Degrade to an alternative activity such as a secondary provider or a cheaper path. Runs the activity
 * like the primary, and it may itself succeed, fail, or issue commands. Declared as
 * `#[Fallback(state:, activity: SecondaryProvider::class)]`.
 */
final readonly class ActivityFallback implements FallbackStrategy
{
    public function __construct(
        private Activity $activity,
    ) {}

    public function execute(array $vars, Metadata $metadata): ActivityResult
    {
        return $this->activity->run($vars, $metadata);
    }
}
