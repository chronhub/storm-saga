<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Semaphore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Semaphore\Event\SemaphoreSlotGranted;
use Storm\Saga\Semaphore\Event\SemaphoreSlotRefused;

/**
 * The two outcomes a waiter's wait state declares, and the wire they travel on.
 *
 * `expiresAt` is carried as a STRING, never a VO, and the grant handler parses it back with
 * `PointInTime::fromStorage()` before it wakes anyone. So the payload key and the string's shape are
 * a contract between a producer and a consumer that never meet, and the only thing standing between
 * them is that the value crosses unchanged.
 *
 * @see \Storm\Tests\Integration\Saga\SemaphoreSlotEventsTest the same contract through the real codec
 */
final class SemaphoreSlotEventsTest extends TestCase
{
    private const string AT = '2026-03-01T10:00:00.123456+00:00';

    #[Test]
    public function a_grant_travels_as_its_resource_and_a_storage_form_expiry(): void
    {
        $payload = new SemaphoreSlotGranted('rail-primary', self::AT)->toPayload();

        $this->assertSame(['resource' => 'rail-primary', 'expires_at' => self::AT], $payload);
    }

    #[Test]
    public function a_grant_rebuilds_from_its_payload(): void
    {
        $event = SemaphoreSlotGranted::fromPayload(['resource' => 'rail-primary', 'expires_at' => self::AT]);

        $this->assertSame('rail-primary', $event->resource);
        $this->assertSame(self::AT, $event->expiresAt);
    }

    #[Test]
    public function the_carried_expiry_is_still_a_point_in_time_the_grant_handler_can_parse(): void
    {
        // the handler drops a grant whose TTL has passed, which it can only decide by parsing this
        // string; a shape fromStorage() refuses would throw there instead, mid-relay
        $event = SemaphoreSlotGranted::fromPayload(['resource' => 'rail-primary', 'expires_at' => self::AT]);

        $this->assertTrue(PointInTime::fromStorage($event->expiresAt)->equals(PointInTime::from(self::AT)));
    }

    #[Test]
    public function a_refusal_carries_the_resource_alone(): void
    {
        // nothing is pending and no later grant will come, so there is no expiry to carry
        $this->assertSame(['resource' => 'rail-primary'], new SemaphoreSlotRefused('rail-primary')->toPayload());
        $this->assertSame('rail-primary', SemaphoreSlotRefused::fromPayload(['resource' => 'rail-primary'])->resource);
    }
}
