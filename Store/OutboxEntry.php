<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Message\Message;

/**
 * One command a step issues, sealed and stamped with its provenance, ready for the outbox row.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public Message $message,
        public string $issuedFromState,
        public int $issuedAtVersion,
        public int $generation,
        public ?string $effectGroup = null,
    ) {}
}
