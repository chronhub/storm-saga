<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when one arm of a join reported its DEFINITIVE failure and the whole join
 * was disposed of in the same step. The saga left through the failure event's own edge, the graph's
 * routing, not the engine's. Telemetry only.
 *
 * How each arm was disposed of:
 *
 * - `failedArm` had nothing to undo, its downstream refused.
 *
 * - `compensated` arms had already completed, or were still in flight, and their undo was issued on
 *   the spot.
 *
 * - `recalled` arms' commands were still pending and were cancelled before the relay ever claimed
 *   them, proven never-dispatched.
 *
 * - `failedUndos` arms' undo threw or refused, each also announced as `CompensationFailed`, the
 *   reconciliation queue's cue.
 *
 * @see CompensationFailed
 * @see SagaJoinArmArrived
 */
final readonly class SagaJoinFailed implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $compensated
     * @param  list<string>  $recalled
     * @param  list<string>  $failedUndos
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public string $failedArm,
        public array $compensated,
        public array $recalled,
        public array $failedUndos,
    ) {}
}
