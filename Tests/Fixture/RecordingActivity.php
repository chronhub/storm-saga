<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * A test Activity: returns a preset result and counts invocations.
 */
final class RecordingActivity implements Activity
{
    public int $calls = 0;

    public function __construct(
        private readonly ActivityResult $result,
    ) {}

    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        $this->calls++;

        return $this->result;
    }
}
