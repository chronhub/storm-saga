<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;
use Throwable;

/**
 * A test Activity that throws, to exercise the handler's "thrown to failure result" path.
 */
final readonly class ThrowingActivity implements Activity
{
    public function __construct(
        private Throwable $error,
    ) {}

    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        throw $this->error;
    }
}
