<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Semaphore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Semaphore\Command\GrantSlot;

final class GrantSlotPayloadTest extends TestCase
{
    #[Test]
    public function the_payload_round_trips_and_reads_its_grant_id_as_a_string(): void
    {
        $slot = new GrantSlot('rail:visa', 'payment', 'p-1', '2026-08-05T10:01:00.000000+00:00', 'ab12');
        $payload = $slot->toPayload();

        $this->assertEquals($slot, GrantSlot::fromPayload($payload));
        // the handler compares the grant id STRICTLY with the holder record's string, so a payload
        // that decoded it as a number is read back as that string, and an absent one stays absent
        $this->assertSame('1234', GrantSlot::fromPayload([...$payload, 'grant_id' => 1234])->grantId);
        $this->assertNull(GrantSlot::fromPayload([...$payload, 'grant_id' => null])->grantId);
    }
}
