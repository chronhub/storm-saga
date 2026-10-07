<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Storm\Saga\Outbox\SagaCommandPublisher;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Saga\Tests\Fixture\RecordingJitter;
use Storm\Serializer\DefaultMessageSerializer;

final class OutboxBackoffCurveTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    #[DataProvider('curve')]
    public function the_backoff_curve_stays_capped_beyond_the_representable_range(int $attempts, int $expected): void
    {
        // the cap must apply to the FLOAT before the cast: 2^(n-1) overflows an int for a large
        // yet accepted max_attempts, and casting first collapsed the capped exponential to the
        // one-second floor, turning the retry policy into a hammer against a failing downstream
        $relay = new SagaOutboxRelay(
            $this->createStub(Connection::class),
            new DefaultMessageSerializer,
            $this->createStub(SagaCommandPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 1,
            backoffMaxSeconds: 60,
        );

        $delay = new ReflectionMethod($relay, 'backoffSeconds')->invoke($relay, $attempts);
        self::assertGreaterThanOrEqual(max(1, intdiv($expected, 2)), $delay);
        self::assertLessThanOrEqual($expected, $delay);
    }

    #[Test]
    public function the_floor_holds_a_degenerate_zero_base_at_one_second(): void
    {
        // the floor is not decoration: a zero base, constructible standalone, would otherwise
        // compute a zero delay and hammer the downstream with no pause at all
        $relay = new SagaOutboxRelay(
            $this->createStub(Connection::class),
            new DefaultMessageSerializer,
            $this->createStub(SagaCommandPublisher::class),
            maxAttempts: 5,
            backoffBaseSeconds: 0,
            backoffMaxSeconds: 60,
        );

        self::assertSame(1, new ReflectionMethod($relay, 'backoffSeconds')->invoke($relay, 1));
    }

    #[Test]
    #[DataProvider('curve')]
    public function each_attempt_asks_the_jitter_for_the_upper_half_of_its_window(int $attempts, int $window): void
    {
        // equal jitter: the draw spans the upper half of the capped window, the lower end floored at
        // one second, and whichever end the jitter answers is the delay
        foreach ([[RecordingJitter::lowest(), max(1, intdiv($window, 2))], [RecordingJitter::highest(), $window]] as [$jitter, $delay]) {
            $relay = new SagaOutboxRelay(
                $this->createStub(Connection::class),
                new DefaultMessageSerializer,
                $this->createStub(SagaCommandPublisher::class),
                maxAttempts: 5,
                backoffBaseSeconds: 1,
                backoffMaxSeconds: 60,
                jitter: $jitter,
            );

            self::assertSame($delay, new ReflectionMethod($relay, 'backoffSeconds')->invoke($relay, $attempts));
            self::assertSame([[max(1, intdiv($window, 2)), $window]], $jitter->asked);
        }
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function curve(): iterable
    {
        yield 'first attempt is the base' => [1, 1];

        yield 'doubling below the cap' => [5, 16];

        yield 'the cap engages' => [7, 60];

        yield 'well past the cap' => [32, 60];

        yield 'past the int64 range' => [70, 60];

        yield 'absurd but accepted configuration' => [200, 60];
    }
}
