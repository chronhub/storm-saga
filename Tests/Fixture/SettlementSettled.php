<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

/**
 * A concrete event implementing {@see SettlementContract}. Confirms a step whose `confirmedBy` names
 * the interface.
 */
final class SettlementSettled implements SettlementContract {}
