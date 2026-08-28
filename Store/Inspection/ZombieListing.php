<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * The result of a zombie-child sweep: the matching rows, and whether the server-side cap cut the
 * answer short.
 *
 * The sibling of `SagaListing`, and it exists for the same reason, which bites harder here: every row
 * of this view is an instruction to cancel something, so an operator who works the list believes the
 * population is finished when the cap merely ran out. The store reads one row past the cap, so
 * truncation is known rather than guessed.
 *
 * @see SagaInspectionGateway::zombieChildren() the producer
 * @see SagaListing the same shape over the saga listing
 */
final readonly class ZombieListing
{
    /**
     * @param  list<ZombieChildSnapshot>  $children  at most `$limit` rows, oldest-started first
     * @param  bool  $truncated  true when at least one further row matched beyond the cap
     * @param  positive-int  $limit  the cap actually applied, after the server-side clamp
     */
    public function __construct(
        public array $children,
        public bool $truncated,
        public int $limit,
    ) {}

    /**
     * The machine shape, snake_case on the wire, the console's `--json` contract.
     *
     * @return array{children: list<array<string, mixed>>, truncated: bool, limit: int}
     */
    public function toArray(): array
    {
        return [
            'children' => array_map(static fn (ZombieChildSnapshot $z): array => $z->toArray(), $this->children),
            'truncated' => $this->truncated,
            'limit' => $this->limit,
        ];
    }
}
