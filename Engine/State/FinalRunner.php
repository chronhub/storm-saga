<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\State;

use Storm\Saga\Engine\StateContext;
use Storm\Saga\Engine\Verdict\Finalize;

/**
 * Reaching a final state completes the workflow.
 */
final readonly class FinalRunner
{
    public function run(StateContext $ctx): Finalize
    {
        return new Finalize($ctx->vars);
    }
}
