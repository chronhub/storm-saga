<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

/**
 * The result of a saga listing: the matching rows, and whether the server-side cap cut the answer
 * short.
 *
 * Not named `*Snapshot` like its neighbors on purpose: those describe one entity, this describes
 * one query's answer. It exists for the `truncated` flag alone. A listing that silently stopped at
 * its cap reads exactly like a listing that found everything, and an operator hunting a stuck saga
 * would conclude "there are only 50" when there are thousands. The cap is a promise the surface
 * keeps; saying it was hit is the other half of that promise.
 *
 * @see SagaInspectionGateway::list() the producer
 */
final readonly class SagaListing
{
    /**
     * @param  list<SagaSummarySnapshot>  $sagas  at most `$limit` rows, oldest-touched first
     * @param  bool  $truncated  true when at least one further row matched beyond the cap
     * @param  positive-int  $limit  the cap actually applied, after the server-side clamp
     */
    public function __construct(
        public array $sagas,
        public bool $truncated,
        public int $limit,
    ) {}

    /**
     * The machine shape, snake_case on the wire, the console's `--json` contract.
     *
     * @return array{sagas: list<array<string, mixed>>, truncated: bool, limit: int}
     */
    public function toArray(): array
    {
        return [
            'sagas' => array_map(static fn (SagaSummarySnapshot $s): array => $s->toArray(), $this->sagas),
            'truncated' => $this->truncated,
            'limit' => $this->limit,
        ];
    }
}
