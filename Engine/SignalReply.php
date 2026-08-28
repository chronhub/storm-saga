<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * What a `signalFor()` step came back with: the step's report, and the handler's typed reply when the
 * signal applied and the handler answered. The pair stays together because collapsing it to the reply
 * alone would make `null` ambiguous: "the handler answered nothing" and "the signal never applied"
 * are different facts, and the caller may branch on both.
 *
 * @see StepExecutor::executeSignalFor()
 */
final readonly class SignalReply
{
    public function __construct(
        public ExecutionReport $report,
        public ?object $result = null,
    ) {}
}
