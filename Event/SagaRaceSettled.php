<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * Emitted, after commit, when a race's first outcome arrived and the losing arms were disposed of in
 * the same step: `winner` kept its effect and joined the compensation log confirmed; `recalled` arms'
 * commands were still pending and were cancelled before the relay ever claimed them, proven
 * never-dispatched; `compensated` arms were already in flight and their undo was issued on the spot;
 * `failed` arms' undo threw or refused, each also announced as `CompensationFailed`, the
 * reconciliation queue's cue. Telemetry only.
 *
 * @see CompensationFailed
 */
final readonly class SagaRaceSettled implements SagaAnnouncement
{
    use ProvideGenerationStamp;

    /**
     * @param  list<string>  $recalled
     * @param  list<string>  $compensated
     * @param  list<string>  $failed
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public int $generation,
        public string $stateKey,
        public string $winner,
        public array $recalled,
        public array $compensated,
        public array $failed,
    ) {}
}
