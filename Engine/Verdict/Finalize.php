<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

/**
 * A final state was reached; the saga completes with the authoritative `$vars`.
 */
final readonly class Finalize implements Verdict
{
    /**
     * @param  array<string, mixed>  $vars
     */
    public function __construct(
        public array $vars = [],
    ) {}
}
