<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Semaphore;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\PointInTime;
use Storm\Saga\Semaphore\Reply\Granted;
use Storm\Saga\Semaphore\Reply\Lost;
use Storm\Saga\Semaphore\Reply\Queued;
use Storm\Saga\Semaphore\Reply\Rejected;
use Storm\Saga\Semaphore\Reply\Renewed;
use Storm\Saga\Semaphore\SemaphoreLedger;
use Storm\Saga\Semaphore\SlotToken;

use function assert;

final class SemaphoreLedgerTest extends TestCase
{
    private PointInTime $now;

    protected function setUp(): void
    {
        $this->now = new PointInTime('2026-08-05T10:00:00.000000+00:00');
    }

    /**
     * @return array<string, mixed>
     */
    private function vars(int $capacity = 2, int $maxQueue = 2, int $grantTtl = 60, int $queueTtl = 120): array
    {
        return SemaphoreLedger::provisionVars('rail:visa', $capacity, $grantTtl, $maxQueue, $queueTtl, 30);
    }

    /**
     * @return array{waiter_type: string, waiter_corr: string, expires_at: string}
     */
    private function holder(string $corr, string $expiresAt): array
    {
        return ['waiter_type' => 'payment', 'waiter_corr' => $corr, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{token: string, waiter_type: string, waiter_corr: string, grant_ttl: int, expires_at: string}
     */
    private function entry(string $corr, int $grantTtl, string $expiresAt): array
    {
        return [
            'token' => SlotToken::of('payment', $corr),
            'waiter_type' => 'payment',
            'waiter_corr' => $corr,
            'grant_ttl' => $grantTtl,
            'expires_at' => $expiresAt,
        ];
    }

    // acquire: grant, queue, reject

    #[Test]
    public function provision_vars_carry_the_declared_bounds_and_empty_books(): void
    {
        $vars = SemaphoreLedger::provisionVars('rail:visa', 3, 60, 5, 120, 30);

        $this->assertSame('rail:visa', $vars[SemaphoreLedger::RESOURCE]);
        $this->assertSame(3, $vars[SemaphoreLedger::CAPACITY]);
        $this->assertSame(5, $vars[SemaphoreLedger::MAX_QUEUE]);
        $this->assertSame(60, $vars[SemaphoreLedger::GRANT_TTL]);
        $this->assertSame(120, $vars[SemaphoreLedger::QUEUE_TTL]);
        $this->assertSame(30, $vars[SemaphoreLedger::SWEEP_INTERVAL]);
        $this->assertSame([], $vars[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $vars[SemaphoreLedger::QUEUE]);
        $this->assertSame(0, $vars[SemaphoreLedger::EXPROPRIATED]);
        $this->assertSame(0, $vars[SemaphoreLedger::LAPSED]);
    }

    #[Test]
    public function a_free_slot_grants_now_with_a_lease_from_this_instant(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars());

        $reply = $ledger->acquire('payment', 'p-1');

        $this->assertInstanceOf(Granted::class, $reply);
        $this->assertSame($this->now->addSeconds(60)->toString(), $reply->expiresAt);
        $this->assertArrayHasKey(SlotToken::of('payment', 'p-1'), $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $ledger->grants()); // an immediate grant is ANSWERED, never re-announced
    }

    #[Test]
    public function the_same_token_gets_the_same_grant_back(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars());
        $first = $ledger->acquire('payment', 'p-1');
        assert($first instanceof Granted);

        $again = $ledger->acquire('payment', 'p-1');

        $this->assertInstanceOf(Granted::class, $again);
        $this->assertSame($first->expiresAt, $again->expiresAt); // no silent extension, Renew is the verb
        $this->assertCount(1, $ledger->vars()[SemaphoreLedger::HOLDERS]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_ledger_whose_bounds_are_missing_grants_nothing(): void
    {
        // The vars are stored state, so a row written before a bound existed, or truncated, hydrates
        // without it. The fallback has to be the CLOSED reading: an absent capacity means no slot,
        // never one slot, or a semaphore would hand out grants it was never provisioned for.
        $ledger = SemaphoreLedger::open($this->now, [SemaphoreLedger::RESOURCE => 'rail:visa']);

        $this->assertInstanceOf(Rejected::class, $ledger->acquire('payment', 'p-1'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_holder_re_asking_under_a_full_house_gets_its_grant_not_a_place_in_line(): void
    {
        // The holder check comes first for this exact case, and only a FULL house shows it: with a
        // free slot the re-ask would be re-granted and look the same. Here, answered later, the
        // holder would be queued behind itself and wait for a slot it is already sitting in.
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $first = $ledger->acquire('payment', 'p-1');
        assert($first instanceof Granted);

        $again = $ledger->acquire('payment', 'p-1');

        $this->assertInstanceOf(Granted::class, $again);
        $this->assertSame($first->expiresAt, $again->expiresAt);
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::QUEUE]);
        // the holder record carries who holds it, not just when it lapses: the sweep reports the
        // expropriated waiter by these two fields, so a record missing one reaps anonymously
        $this->assertSame(
            ['waiter_type' => 'payment', 'waiter_corr' => 'p-1', 'expires_at' => $first->expiresAt],
            $ledger->vars()[SemaphoreLedger::HOLDERS][SlotToken::of('payment', 'p-1')],
        );
    }

    #[Test]
    public function a_full_house_queues_fifo_with_positions(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $ledger->acquire('payment', 'p-1');

        $second = $ledger->acquire('payment', 'p-2');
        $third = $ledger->acquire('payment', 'p-3');

        $this->assertInstanceOf(Queued::class, $second);
        $this->assertSame(1, $second->position);
        $this->assertInstanceOf(Queued::class, $third);
        $this->assertSame(2, $third->position);
    }

    #[Test]
    public function a_queued_token_re_asks_and_keeps_its_place(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2');

        $again = $ledger->acquire('payment', 'p-2');

        $this->assertInstanceOf(Queued::class, $again);
        $this->assertSame(1, $again->position);
        $this->assertCount(1, $ledger->vars()[SemaphoreLedger::QUEUE]);
    }

    #[Test]
    public function a_full_queue_rejects_and_stores_nothing(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1, maxQueue: 1));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2');

        $reply = $ledger->acquire('payment', 'p-3');

        $this->assertInstanceOf(Rejected::class, $reply);
        $this->assertSame(1, $reply->queueLimit);
        $this->assertCount(1, $ledger->vars()[SemaphoreLedger::QUEUE]);
    }

    #[Test]
    public function a_zero_queue_bound_rejects_at_the_first_full_house(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1, maxQueue: 0));
        $ledger->acquire('payment', 'p-1');

        $this->assertInstanceOf(Rejected::class, $ledger->acquire('payment', 'p-2'));
    }

    // release: free, promote, no-op

    #[Test]
    public function release_frees_the_slot_and_promotes_the_head_with_its_wake_up(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2', grantTtlSeconds: 90);

        $ledger->release('payment', 'p-1');

        $holders = $ledger->vars()[SemaphoreLedger::HOLDERS];
        // the promoted entry becomes a holder record of the same shape an acquire writes, keyed by
        // its token as a STRING: a queue entry's token arrives from stored vars, where a numeric-
        // looking one would have become an int on the way in
        $this->assertSame(
            ['waiter_type' => 'payment', 'waiter_corr' => 'p-2', 'expires_at' => $this->now->addSeconds(90)->toString()],
            $holders[SlotToken::of('payment', 'p-2')] ?? null,
        );
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::QUEUE]);
        $this->assertCount(1, $ledger->grants());
        $this->assertSame('p-2', $ledger->grants()[0]->waiterCorrelation);
        $this->assertSame('rail:visa', $ledger->grants()[0]->resource);
        // the promoted lease starts at PROMOTION with the ttl asked at enqueue, never at enqueue time
        $this->assertSame($this->now->addSeconds(90)->toString(), $ledger->grants()[0]->expiresAt);
    }

    #[Test]
    public function release_of_an_unknown_token_is_a_no_op(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars());
        $ledger->acquire('payment', 'p-1');

        $ledger->release('payment', 'p-ghost');

        $this->assertCount(1, $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $ledger->grants());
    }

    #[Test]
    public function release_clears_a_queue_entry_without_touching_the_house(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2');

        $ledger->release('payment', 'p-2');

        $this->assertSame([], $ledger->vars()[SemaphoreLedger::QUEUE]);
        $this->assertArrayHasKey(SlotToken::of('payment', 'p-1'), $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $ledger->grants());
    }

    #[Test]
    #[Group('adversarial')]
    public function releasing_from_the_middle_of_the_queue_closes_the_gap_behind_it(): void
    {
        // The queue is a LIST and its positions are read off the index, so a hole left by a departure
        // is not cosmetic: the waiter behind would be told it is third when it is second, and every
        // later re-ask would keep repeating the wrong place.
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1, maxQueue: 3));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2');
        $ledger->acquire('payment', 'p-3');
        $ledger->acquire('payment', 'p-4');

        $ledger->release('payment', 'p-3');

        $this->assertSame([0, 1], array_keys($ledger->vars()[SemaphoreLedger::QUEUE]));
        $this->assertEquals(new Queued(2), $ledger->acquire('payment', 'p-4'));
    }

    // renew: re-stamp or lost

    #[Test]
    public function renew_re_stamps_the_lease_from_now(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars());
        $ledger->acquire('payment', 'p-1');

        $reply = $ledger->renew('payment', 'p-1', grantTtlSeconds: 300);

        $this->assertInstanceOf(Renewed::class, $reply);
        $this->assertSame($this->now->addSeconds(300)->toString(), $reply->expiresAt);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lease_is_never_born_already_dead(): void
    {
        // The client guards its own callers, but the ledger is also driven by a queue entry's stored
        // ttl, which no guard re-reads. A zero or negative one would mint an expiry at or before now,
        // and the very next opening would reap the slot it just granted.
        $ledger = SemaphoreLedger::open($this->now, $this->vars());

        $reply = $ledger->renew('payment', 'p-1', grantTtlSeconds: 0);
        $this->assertInstanceOf(Lost::class, $reply); // absent, so nothing to floor yet

        $ledger->acquire('payment', 'p-1');
        $renewed = $ledger->renew('payment', 'p-1', grantTtlSeconds: 0);

        $this->assertInstanceOf(Renewed::class, $renewed);
        $this->assertSame($this->now->addSeconds(1)->toString(), $renewed->expiresAt);
    }

    #[Test]
    public function renew_of_an_absent_grant_answers_lost(): void
    {
        $ledger = SemaphoreLedger::open($this->now, $this->vars());

        $this->assertInstanceOf(Lost::class, $ledger->renew('payment', 'p-ghost'));
    }

    // the reap: expropriation, lapse, promotion

    #[Test]
    public function opening_reaps_an_expired_lease_expropriates_and_promotes(): void
    {
        $vars = $this->vars(capacity: 1);
        $vars[SemaphoreLedger::HOLDERS] = [
            SlotToken::of('payment', 'p-dead') => $this->holder('p-dead', $this->now->subSeconds(1)->toString()),
        ];
        $vars[SemaphoreLedger::QUEUE] = [
            $this->entry('p-next', 60, $this->now->addSeconds(100)->toString()),
        ];

        $ledger = SemaphoreLedger::open($this->now, $vars);

        $this->assertSame(1, $ledger->vars()[SemaphoreLedger::EXPROPRIATED]);
        $holders = $ledger->vars()[SemaphoreLedger::HOLDERS];
        $this->assertArrayNotHasKey(SlotToken::of('payment', 'p-dead'), $holders);
        $this->assertArrayHasKey(SlotToken::of('payment', 'p-next'), $holders);
        $this->assertCount(1, $ledger->grants());
        // the promoted lease starts at the reap, not at enqueue
        $this->assertSame($this->now->addSeconds(60)->toString(), $ledger->grants()[0]->expiresAt);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lease_expiring_exactly_now_is_reaped(): void
    {
        $vars = $this->vars();
        $vars[SemaphoreLedger::HOLDERS] = [
            SlotToken::of('payment', 'p-1') => $this->holder('p-1', $this->now->toString()),
        ];

        $ledger = SemaphoreLedger::open($this->now, $vars);

        $this->assertSame(1, $ledger->vars()[SemaphoreLedger::EXPROPRIATED]);
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::HOLDERS]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_lapsed_queue_entry_is_dropped_never_promoted(): void
    {
        $vars = $this->vars(capacity: 1);
        $vars[SemaphoreLedger::QUEUE] = [
            $this->entry('p-late', 60, $this->now->subSeconds(1)->toString()),
        ];

        $ledger = SemaphoreLedger::open($this->now, $vars);

        $this->assertSame(1, $ledger->vars()[SemaphoreLedger::LAPSED]);
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::QUEUE]);
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $ledger->grants());
    }

    #[Test]
    #[Group('adversarial')]
    public function renewing_after_expropriation_cannot_resurrect_the_lease(): void
    {
        $vars = $this->vars(capacity: 1);
        $vars[SemaphoreLedger::HOLDERS] = [
            SlotToken::of('payment', 'p-slow') => $this->holder('p-slow', $this->now->subSeconds(1)->toString()),
        ];
        $vars[SemaphoreLedger::QUEUE] = [
            $this->entry('p-next', 60, $this->now->addSeconds(100)->toString()),
        ];

        $ledger = SemaphoreLedger::open($this->now, $vars);
        $reply = $ledger->renew('payment', 'p-slow');

        $this->assertInstanceOf(Lost::class, $reply);
        $this->assertArrayHasKey(SlotToken::of('payment', 'p-next'), $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertArrayNotHasKey(SlotToken::of('payment', 'p-slow'), $ledger->vars()[SemaphoreLedger::HOLDERS]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_withdrawn_waiter_whose_grant_raced_in_flight_leaves_no_ghost_slot(): void
    {
        // the promotion happened (p-2 is a holder) while p-2's own deadline was abandoning; its
        // withdraw must clear the HOLDER side too, or the slot leaks until the TTL
        $ledger = SemaphoreLedger::open($this->now, $this->vars(capacity: 1));
        $ledger->acquire('payment', 'p-1');
        $ledger->acquire('payment', 'p-2');
        $ledger->release('payment', 'p-1'); // promotes p-2

        $ledger->release('payment', 'p-2'); // the withdraw, arriving after the promotion

        $this->assertSame([], $ledger->vars()[SemaphoreLedger::HOLDERS]);
        $this->assertSame([], $ledger->vars()[SemaphoreLedger::QUEUE]);
    }
}
