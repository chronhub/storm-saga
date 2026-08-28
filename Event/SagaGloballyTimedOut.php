<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted after commit when a saga exceeds its global deadline `#[Workflow(globalTimeout:)]`, having
 * run too long overall, and is forced to resolve from `$from` by one of:
 *
 * - Roll back confirmed steps.
 *
 * - Drive the workflow's `onGlobalTimeout`.
 *
 * - Halt, when there is no target and nothing safe to undo, or when bounded at an effect-gating wait
 *   where the cap halts even though `onGlobalTimeout` is declared.
 *
 * Telemetry only; distinct from a per-state timeout so observers can tell "this state timed out" from
 * "the whole saga timed out".
 *
 * @see SagaStarted
 */
final readonly class SagaGloballyTimedOut implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $from,
    ) {}
}
