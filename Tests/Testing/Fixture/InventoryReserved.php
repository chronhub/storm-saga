<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Testing\Fixture;

/**
 * The checkout sample's external outcome, delivered by the test the way a consumer would route it.
 */
final readonly class InventoryReserved
{
    public function __construct(
        public string $reservationId,
    ) {}
}
