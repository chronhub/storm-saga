<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

/**
 * A confirming-event family, declared as an interface to pin that `compensationConfirmedBy` matches
 * by subtype rather than by exact class.
 */
interface SettlementContract {}
