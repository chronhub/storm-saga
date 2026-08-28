<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Semaphore;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Engine\SagaEngine;
use Storm\Saga\Semaphore\Exception\InvalidSemaphoreProvision;
use Storm\Saga\Semaphore\Exception\SemaphoreUnavailable;
use Storm\Saga\Semaphore\Reply\Lost;
use Storm\Saga\Semaphore\Reply\Renewed;
use Storm\Saga\Semaphore\SemaphoreClient;

/**
 * The client's own refusals, the ones that never reach the guardian: a provision whose bounds make
 * no semaphore, and a renew whose answer the client cannot read. Everything the guardian itself
 * decides is covered against real Postgres by the integration suite.
 */
final class SemaphoreClientTest extends TestCase
{
    /**
     * A bound that cannot describe a semaphore is refused at the door, with the offending value in
     * the message: a capacity below one grants nothing, a negative queue bounds nothing, and a TTL
     * below a second expires before it is read.
     *
     * @param  array{int, int, int, ?int, ?int}  $bounds
     */
    #[Test]
    #[DataProvider('nonsensicalBounds')]
    public function provision_refuses_a_bound_that_describes_no_semaphore(array $bounds, string $expected): void
    {
        [$capacity, $grantTtl, $maxQueue, $queueTtl, $sweep] = $bounds;
        $client = new SemaphoreClient($this->createStub(SagaEngine::class));

        $this->expectException(InvalidSemaphoreProvision::class);
        $this->expectExceptionMessageIsOrContains($expected);

        $client->provision('rail:visa', $capacity, $grantTtl, $maxQueue, $queueTtl, $sweep);
    }

    /**
     * @return array<string, array{array{int, int, int, ?int, ?int}, string}>
     */
    public static function nonsensicalBounds(): array
    {
        return [
            'no slot at all' => [[0, 60, 4, null, null], 'at least one slot, got capacity 0'],
            'a negative queue' => [[1, 60, -1, null, null], 'cannot be negative, got -1'],
            'a grant that never lasts' => [[1, 0, 4, null, null], 'grant TTL must be at least one second, got 0'],
            'a patience that never lasts' => [[1, 60, 4, 0, null], 'queue TTL must be at least one second, got 0'],
            'a sweep that never comes' => [[1, 60, 4, null, 0], 'sweep interval must be at least one second, got 0'],
        ];
    }

    #[Test]
    public function provision_accepts_the_smallest_semaphore_that_is_one(): void
    {
        // The floor twin of the refusals above, and the bounds are not symmetric: one slot, one
        // second of grant, and a queue of ZERO, which bounds a semaphore that simply never queues.
        // Read as `<= `, each guard would refuse the smallest thing it exists to allow.
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('start')->with(
            SemaphoreClient::WORKFLOW_TYPE,
            'rail:visa',
            $this->callback(static fn (array $vars): bool => $vars['capacity'] === 1
                && $vars['max_queue'] === 0
                && $vars['grant_ttl'] === 1),
        )->willReturn(true);

        $client = new SemaphoreClient($engine);

        $this->assertTrue($client->provision('rail:visa', 1, 1, 0));
    }

    #[Test]
    public function provision_derives_the_patience_and_the_sweep_it_was_not_given(): void
    {
        // Two defaults nobody declares and everybody gets: a queue TTL falling back to the grant's,
        // and a sweep at half the grant, floored at one second so a short grant still gets swept.
        // They ride into the guardian's own vars, so getting them wrong is silent.
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('start')->with(
            SemaphoreClient::WORKFLOW_TYPE,
            'rail:visa',
            $this->callback(static fn (array $vars): bool => $vars['queue_ttl'] === 61
                && $vars['sweep_interval'] === 30),
        )->willReturn(true);

        $client = new SemaphoreClient($engine);

        $client->provision('rail:visa', 2, 61, 4);
    }

    #[Test]
    public function provision_floors_the_derived_sweep_at_one_second(): void
    {
        // A one-second grant halves to zero, and a sweep that never comes leaves the books to rot;
        // the floor is what stops the derivation from producing a value its own guard would refuse.
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->once())->method('start')->with(
            SemaphoreClient::WORKFLOW_TYPE,
            'rail:visa',
            $this->callback(static fn (array $vars): bool => $vars['sweep_interval'] === 1),
        )->willReturn(true);

        $client = new SemaphoreClient($engine);

        $client->provision('rail:visa', 1, 1, 0);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_refused_provision_never_births_the_guardian(): void
    {
        // the order is the point: a half-provisioned semaphore would hold a resource under bounds
        // nobody accepted, so every bound is checked before the engine is touched at all
        $engine = $this->createMock(SagaEngine::class);
        $engine->expects($this->never())->method('start');

        $client = new SemaphoreClient($engine);

        $this->expectException(InvalidSemaphoreProvision::class);

        $client->provision('rail:visa', 0, 60, 4);
    }

    #[Test]
    public function renew_returns_either_honest_answer_untouched(): void
    {
        // The two the holder acts on: its bail was extended, or the slot is gone. The refusal below
        // is written as "neither of these", so without a case for each the guard reads as "either of
        // these" just as well, and every successful renew would raise unavailability.
        $renewed = new Renewed('2026-06-01T00:00:00.000000+00:00');
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('signalFor')->willReturn($renewed);

        $this->assertSame($renewed, new SemaphoreClient($engine)->renew('rail:visa', 'rail_capped_auth', 'auth-1', 60));

        $lost = new Lost;
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('signalFor')->willReturn($lost);

        $this->assertSame($lost, new SemaphoreClient($engine)->renew('rail:visa', 'rail_capped_auth', 'auth-1', 60));
    }

    #[Test]
    #[Group('adversarial')]
    public function renew_refuses_an_answer_it_cannot_read(): void
    {
        // a renew has exactly two honest outcomes, renewed or lost. Anything else, including the
        // silence of a guardian that is absent or frozen, is unavailability: the holder must hear it
        // loudly rather than carry on believing its bail was extended
        $engine = $this->createStub(SagaEngine::class);
        $engine->method('signalFor')->willReturn(null);

        $client = new SemaphoreClient($engine);

        $this->expectException(SemaphoreUnavailable::class);
        $this->expectExceptionMessageIsOrContains('rail:visa');

        $client->renew('rail:visa', 'rail_capped_auth', 'auth-1', 60);
    }
}
