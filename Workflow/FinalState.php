<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * A terminal state: reaching it completes the workflow. No activity; a declared transition out is dead by
 * construction, and a final state never drives; it is kept constructible for parity, not rejected.
 */
final class FinalState extends State
{
    public function __construct(string $key = 'done', array $transitions = [])
    {
        parent::__construct($key, $transitions);
    }
}
