<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Storm\Saga\Outbox\SagaCommandPublisher;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Serializer\DefaultMessageSerializer;

final class OutboxClaimLeaseTest extends TestCase
{
    #[Test]
    public function a_claim_holds_its_rows_for_five_minutes_by_default(): void
    {
        // the default serves a standalone construction, and matches the bundle's own for
        // `claim_lease_seconds`: long enough for the slowest batch to publish and mark its rows, short
        // enough to hand a lost drain's rows on soon
        $relay = new SagaOutboxRelay(
            $this->createStub(Connection::class),
            new DefaultMessageSerializer,
            $this->createStub(SagaCommandPublisher::class),
        );

        self::assertSame(300, new ReflectionProperty(SagaOutboxRelay::class, 'claimLeaseSeconds')->getValue($relay));
    }
}
