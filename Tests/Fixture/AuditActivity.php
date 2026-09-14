<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Storm\Saga\Workflow\Activity;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * An activity used by one workflow and no other, so its absence from a resolution log proves that
 * assembling a different workflow did not reach it.
 */
final class AuditActivity implements Activity
{
    public function run(array $vars, Metadata $metadata): ActivityResult
    {
        return ActivityResult::success($vars);
    }
}
