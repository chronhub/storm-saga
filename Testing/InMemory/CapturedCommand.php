<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Storm\Saga\Outbox\OutboxStatus;

/**
 * One sealed command as the runtime captured it: the stable message id, the issuing saga, the
 * status, and the payload REBUILT through the message serializer, so what a test inspects is what
 * a wire consumer would receive, never the object the workflow handed the engine.
 */
final readonly class CapturedCommand
{
    public function __construct(
        public string $messageId,
        public string $workflowType,
        public string $correlationId,
        public OutboxStatus $status,
        private object $payload,
    ) {}

    public function payload(): object
    {
        return $this->payload;
    }
}
