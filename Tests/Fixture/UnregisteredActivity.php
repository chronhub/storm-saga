<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * A perfectly valid activity that no test container provides.
 *
 * The class exists, so the declaration reads as well-formed everywhere a reader looks; only
 * resolving it from the activity locator fails, which is the fault an assembly gate has to catch and
 * a declaration table cannot.
 */
final class UnregisteredActivity implements Activity
{
    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        return ActivityResult::success($vars);
    }
}
