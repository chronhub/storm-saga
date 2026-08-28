<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\Testing\InMemory\ControlledClock;
use Storm\Saga\Testing\InMemory\InMemoryCircuitBreakers;
use Storm\Saga\Testing\InMemory\InMemorySagaState;

/**
 * The breaker store the in-memory runtime wires into every scenario, judged against the rules its
 * DBAL twin enforces in SQL, since a consumer writing a resilience test reads the same answers
 * from both or the kit teaches a fiction.
 *
 * The two agree on the four rules asserted here: an absent key reads closed with no failures and
 * no stamp, a success on an absent key writes nothing at all, the failure that REACHES the
 * threshold opens and stamps, and every later failure re-stamps, so the cooldown starts from the
 * most recent failure rather than the first.
 *
 * @see \Storm\Saga\CircuitBreaker\Dbal\DbalCircuitBreakerStorage
 */
final class InMemoryCircuitBreakersTest extends TestCase
{
    #[Test]
    public function an_unknown_key_reads_closed_with_nothing_recorded(): void
    {
        $snapshot = $this->breakers()->read('never-touched');

        $this->assertSame(BreakerState::Closed, $snapshot->state);
        $this->assertSame(0, $snapshot->failures);
        $this->assertNull($snapshot->openedAt);
    }

    #[Test]
    public function a_success_on_an_unknown_key_records_nothing(): void
    {
        // the twin's UPDATE matches no row and inserts nothing; an absent key ALREADY reads
        // closed, so materializing an entry here would be a write the database never makes
        $state = new InMemorySagaState;

        $this->breakers($state)->recordSuccess('never-touched');

        $this->assertSame([], $state->breakers);
    }

    #[Test]
    public function failures_below_the_threshold_accumulate_without_opening(): void
    {
        $breakers = $this->breakers();

        $breakers->recordFailure('rail', 3);
        $breakers->recordFailure('rail', 3);

        $snapshot = $breakers->read('rail');
        $this->assertSame(BreakerState::Closed, $snapshot->state);
        $this->assertSame(2, $snapshot->failures);
        $this->assertNull($snapshot->openedAt);
    }

    #[Test]
    public function the_failure_that_reaches_the_threshold_opens_and_stamps_the_clock(): void
    {
        $clock = new ControlledClock(PointInTime::from('2026-01-01T00:00:00.000000+00:00'));
        $breakers = $this->breakers(clock: $clock);

        $breakers->recordFailure('rail', 2);
        $clock->advanceSeconds(30);
        $breakers->recordFailure('rail', 2);

        $snapshot = $breakers->read('rail');
        $this->assertSame(BreakerState::Open, $snapshot->state);
        $this->assertSame(2, $snapshot->failures);
        $this->assertSame('2026-01-01T00:00:30.000000+00:00', $snapshot->openedAt?->toString());
    }

    #[Test]
    public function a_failure_while_open_restarts_the_cooldown_from_the_latest_one(): void
    {
        $clock = new ControlledClock(PointInTime::from('2026-01-01T00:00:00.000000+00:00'));
        $breakers = $this->breakers(clock: $clock);
        $breakers->recordFailure('rail', 1);

        $clock->advanceSeconds(60);
        $breakers->recordFailure('rail', 1);

        $this->assertSame('2026-01-01T00:01:00.000000+00:00', $breakers->read('rail')->openedAt?->toString());
    }

    #[Test]
    public function a_success_resets_a_recorded_breaker_whatever_its_state(): void
    {
        $breakers = $this->breakers();
        $breakers->recordFailure('rail', 1);

        $breakers->recordSuccess('rail');

        $snapshot = $breakers->read('rail');
        $this->assertSame(BreakerState::Closed, $snapshot->state);
        $this->assertSame(0, $snapshot->failures);
        $this->assertNull($snapshot->openedAt);
    }

    private function breakers(?InMemorySagaState $state = null, ?ControlledClock $clock = null): InMemoryCircuitBreakers
    {
        return new InMemoryCircuitBreakers(
            $state ?? new InMemorySagaState,
            $clock ?? new ControlledClock(PointInTime::from('2026-01-01T00:00:00.000000+00:00')),
        );
    }
}
