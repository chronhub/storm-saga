<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Contracts\Message\RetryableDelivery;
use Storm\Saga\Exception\SagaFenceBusy;
use Storm\Saga\Exception\SagaOutcomeNotYetApplicable;

final class RetryableSagaDeliveryTest extends TestCase
{
    #[Test]
    public function temporary_delivery_failures_declare_retry_without_a_transport_dependency(): void
    {
        self::assertInstanceOf(RetryableDelivery::class, SagaOutcomeNotYetApplicable::whileDelivering('shipment', 's-1', stdClass::class));
        self::assertInstanceOf(RetryableDelivery::class, SagaFenceBusy::whileDelivering('shipment', 's-1'));
        self::assertInstanceOf(RetryableDelivery::class, SagaFenceBusy::whileStarting('shipment', 's-1'));
    }
}
