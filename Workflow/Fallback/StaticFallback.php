<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Fallback;

use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\Metadata;

/**
 * Degrade to static or default values: succeed with the current vars merged with a fixed set such as a
 * cached price or a sane default, letting the saga move on. Declared as `#[Fallback(state:, vars: [...])]`.
 */
final readonly class StaticFallback implements FallbackStrategy
{
    /**
     * @param  array<string, mixed>  $vars  the defaults to merge in, overriding the incoming vars
     */
    public function __construct(
        private array $vars,
    ) {}

    public function execute(array $vars, Metadata $metadata): ActivityResult
    {
        return ActivityResult::success([...$vars, ...$this->vars]);
    }
}
