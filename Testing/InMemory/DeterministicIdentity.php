<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Contracts\Message\MetaIdentityGenerator;

/**
 * Stable incremental message ids off the shared state's counter, so a scenario's sealed commands
 * carry the same ids run after run and an assertion can name one literally. The counter lives in
 * the shared state on purpose: an id minted inside a step that rolls back is minted again by the
 * retry, keeping reruns byte-identical.
 */
final readonly class DeterministicIdentity implements MetaIdentityGenerator
{
    public function __construct(
        private InMemorySagaState $state,
    ) {}

    public function generate(): string
    {
        return sprintf('in-memory-%08d', $this->state->nextMessageSeq++);
    }
}
