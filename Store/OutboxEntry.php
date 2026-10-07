<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Message\Message;
use Storm\Saga\Outbox\CommandPurpose;

/**
 * One command a step issues, sealed and stamped with its provenance and its purpose, ready for the
 * outbox row.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public Message $message,
        public string $issuedFromState,
        public int $issuedAtVersion,
        public int $generation,
        public CommandPurpose $purpose,
        public ?string $effectGroup = null,
    ) {}
}
