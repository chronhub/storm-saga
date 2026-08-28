<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Verdict;

/**
 * A dead end: no matching transition where one was needed. May still carry commands the state had
 * already issued before hitting the dead end, a successfully run activity with no matching edge;
 * they ride the outbox regardless.
 */
final readonly class Halt implements Verdict
{
    /**
     * @param  list<object>  $commands  commands already issued before the dead-end, still emitted
     */
    public function __construct(
        public array $commands = [],
    ) {}
}
