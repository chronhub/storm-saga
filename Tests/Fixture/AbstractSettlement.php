<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

/**
 * An abstract event base. Pins that `onEvent` routing demands a CONCRETE class, since an abstract is
 * never selectable by exact-class comparison.
 */
abstract class AbstractSettlement implements SettlementContract {}
