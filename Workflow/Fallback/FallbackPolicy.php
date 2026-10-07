<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Fallback;

/**
 * A state's fallback chain: the ordered `FallbackCandidate`s, each a `#[Fallback]` on the state. The
 * engine tries the applicable ones in order until one returns `Success`; if none salvages the step, it
 * takes the `Failure` transition. Declaring several `#[Fallback]` on one state is the chain.
 *
 * @see FallbackCandidate
 */
final readonly class FallbackPolicy
{
    /**
     * @param  list<FallbackCandidate>  $candidates  in declaration order
     */
    public function __construct(
        public array $candidates,
    ) {}

    /**
     * The strategies whose guard applies, in order, the chain to try for these vars.
     *
     * @param  array<string, mixed>  $vars
     * @return list<FallbackStrategy>
     */
    public function candidatesFor(array $vars): array
    {
        return array_column(
            array_filter($this->candidates, static fn (FallbackCandidate $candidate): bool => $candidate->applies($vars)),
            'strategy',
        );
    }
}
