<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore;

use Storm\Clock\PointInTime;
use Storm\Saga\Semaphore\Command\GrantSlot;
use Storm\Saga\Semaphore\Reply\Granted;
use Storm\Saga\Semaphore\Reply\Lost;
use Storm\Saga\Semaphore\Reply\Queued;
use Storm\Saga\Semaphore\Reply\Rejected;
use Storm\Saga\Semaphore\Reply\Renewed;

/**
 * The semaphore's pure bookkeeping: every verb and the sweep drive this one class over the workflow's
 * `vars`, under the instance's fence, so there is never a second copy of the truth to reconcile.
 *
 * Opening the ledger REAPS first:
 *
 * - Expired grants are expropriated;
 * - Lapsed queue entries are dropped;
 * - Freed slots are promoted.
 *
 * Every verb therefore decides against the honest current state, and the scheduled sweep is just an
 * open with nothing else to say: the reap is lazy at every touch, the schedule is the backstop for a
 * semaphore nobody touches.
 *
 * Doctrine carried here:
 *
 * - The release authority is the HOLDER, the TTL is the backstop;
 *
 * - A grant's lease starts when the slot is held, at acquire or at promotion, never at enqueue;
 *
 * - A promotion emits its {@see GrantSlot} wake-up in the same step that moved the entry, so the
 *   bookkeeping and the wake-up commit or vanish together.
 *
 * Expropriations and lapses are counted in the vars because a cap that was silently
 * exceeded-and-healed is an ops fact worth seeing.
 */
final class SemaphoreLedger
{
    public const string RESOURCE = 'resource';

    public const string CAPACITY = 'capacity';

    public const string MAX_QUEUE = 'max_queue';

    public const string GRANT_TTL = 'grant_ttl';

    public const string QUEUE_TTL = 'queue_ttl';

    public const string SWEEP_INTERVAL = 'sweep_interval';

    public const string HOLDERS = 'holders';

    public const string QUEUE = 'queue';

    /** Running count of grants the sweep expropriated, a lease outlived by its TTL. */
    public const string EXPROPRIATED = 'expropriated';

    /** Running count of queue entries dropped by their backstop TTL, a waiter that never withdrew. */
    public const string LAPSED = 'lapsed';

    /** @var list<GrantSlot> */
    private array $grants = [];

    /**
     * @param  array<string, mixed>  $vars
     */
    private function __construct(
        private readonly PointInTime $now,
        private array $vars,
    ) {
        $this->reap();
    }

    /**
     * Open the ledger over the workflow's vars at `now`, reaping immediately.
     *
     * @param  array<string, mixed>  $vars
     */
    public static function open(PointInTime $now, array $vars): self
    {
        return new self($now, $vars);
    }

    /**
     * The initial vars a provisioned semaphore is born with.
     *
     * @return array<string, mixed>
     */
    public static function provisionVars(
        string $resource,
        int $capacity,
        int $grantTtlSeconds,
        int $maxQueue,
        int $queueTtlSeconds,
        int $sweepIntervalSeconds,
    ): array {
        return [
            self::RESOURCE => $resource,
            self::CAPACITY => $capacity,
            self::MAX_QUEUE => $maxQueue,
            self::GRANT_TTL => $grantTtlSeconds,
            self::QUEUE_TTL => $queueTtlSeconds,
            self::SWEEP_INTERVAL => $sweepIntervalSeconds,
            self::HOLDERS => [],
            self::QUEUE => [],
            self::EXPROPRIATED => 0,
            self::LAPSED => 0,
        ];
    }

    /**
     * Ask for a slot: the same token always gets the same answer back, its current grant or queue
     * place, so a redelivered acquire is free. A free slot grants now, the lease running from this
     * instant; a full house queues under the backstop TTL; a full queue answers `Rejected` and
     * stores nothing.
     */
    public function acquire(string $waiterType, string $waiterCorrelation, ?int $grantTtlSeconds = null): Granted|Queued|Rejected
    {
        $token = SlotToken::of($waiterType, $waiterCorrelation);
        $holders = $this->holders();

        if (isset($holders[$token])) {
            return new Granted((string) $holders[$token]['expires_at']);
        }

        $queue = $this->queue();
        foreach ($queue as $index => $entry) {
            if ($entry['token'] === $token) {
                return new Queued($index + 1);
            }
        }

        $ttl = $grantTtlSeconds ?? $this->intVar(self::GRANT_TTL);

        if (count($holders) < $this->intVar(self::CAPACITY)) {
            $expiresAt = $this->lease($ttl);
            $holders[$token] = [
                'waiter_type' => $waiterType,
                'waiter_corr' => $waiterCorrelation,
                'expires_at' => $expiresAt,
            ];
            $this->vars[self::HOLDERS] = $holders;

            return new Granted($expiresAt);
        }

        $maxQueue = $this->intVar(self::MAX_QUEUE);
        if (count($queue) >= $maxQueue) {
            return new Rejected($maxQueue);
        }

        $queue[] = [
            'token' => $token,
            'waiter_type' => $waiterType,
            'waiter_corr' => $waiterCorrelation,
            'grant_ttl' => $ttl,
            'expires_at' => $this->lease($this->intVar(self::QUEUE_TTL)),
        ];
        $this->vars[self::QUEUE] = $queue;

        return new Queued(count($queue));
    }

    /**
     * Give the slot back, or abandon the queue place; both sides are cleared because a grant may race
     * the waiter's abandonment in flight. Unknown token is a no-op. Freed capacity promotes.
     */
    public function release(string $waiterType, string $waiterCorrelation): void
    {
        $token = SlotToken::of($waiterType, $waiterCorrelation);

        $holders = $this->holders();
        unset($holders[$token]);
        $this->vars[self::HOLDERS] = $holders;

        // Equivalent mutant on `array_values`: it pairs with the one in `queue()`, and `promote()`
        // below rewrites the queue through that reader unconditionally, so each re-indexing masks the
        // other. Neither is individually observable; both stay, the list shape being an invariant of
        // the stored vars rather than of one write path.
        // @infection-ignore-all; equivalent: the array_values twin argued just above
        $this->vars[self::QUEUE] = array_values(array_filter(
            $this->queue(),
            static fn (array $entry): bool => $entry['token'] !== $token,
        ));

        $this->promote();
    }

    /**
     * Re-stamp a living grant's lease from now. A grant already swept answers `Lost`; renewing cannot
     * resurrect an expropriated lease, its slot may already be someone else's.
     */
    public function renew(string $waiterType, string $waiterCorrelation, ?int $grantTtlSeconds = null): Renewed|Lost
    {
        $token = SlotToken::of($waiterType, $waiterCorrelation);
        $holders = $this->holders();

        if (! isset($holders[$token])) {
            return new Lost;
        }

        $expiresAt = $this->lease($grantTtlSeconds ?? $this->intVar(self::GRANT_TTL));
        $holders[$token]['expires_at'] = $expiresAt;
        $this->vars[self::HOLDERS] = $holders;

        return new Renewed($expiresAt);
    }

    /**
     * The vars to persist back into the workflow, after the reap and whatever verb ran.
     *
     * @return array<string, mixed>
     */
    public function vars(): array
    {
        return $this->vars;
    }

    /**
     * The wake-up commands the promotions of this ledger session produced, to ride the step's outbox.
     *
     * @return list<GrantSlot>
     */
    public function grants(): array
    {
        return $this->grants;
    }

    /**
     * Expel every grant whose lease is over, counting each as an expropriation; drop every queue
     * entry past its backstop TTL, counting each as lapsed; then promote into the freed slots. A
     * lease is over at its exact expiry instant: the sweep that fires ON the boundary reclaims.
     */
    private function reap(): void
    {
        $holders = [];
        $expropriated = $this->intVar(self::EXPROPRIATED);
        foreach ($this->holders() as $token => $grant) {
            if (PointInTime::fromStorage((string) $grant['expires_at'])->isAfter($this->now)) {
                $holders[$token] = $grant;
            } else {
                $expropriated++;
            }
        }
        $this->vars[self::HOLDERS] = $holders;
        $this->vars[self::EXPROPRIATED] = $expropriated;

        $queue = [];
        $lapsed = $this->intVar(self::LAPSED);
        foreach ($this->queue() as $entry) {
            if (PointInTime::fromStorage((string) $entry['expires_at'])->isAfter($this->now)) {
                $queue[] = $entry;
            } else {
                $lapsed++;
            }
        }
        $this->vars[self::QUEUE] = $queue;
        $this->vars[self::LAPSED] = $lapsed;

        $this->promote();
    }

    /**
     * Turn queue heads into grants while capacity is free, FIFO; each promotion's lease starts NOW,
     * and its wake-up command is collected for the step's outbox.
     */
    private function promote(): void
    {
        $holders = $this->holders();
        $queue = $this->queue();
        $capacity = $this->intVar(self::CAPACITY);

        while (count($holders) < $capacity && $queue !== []) {
            $entry = array_shift($queue);
            $expiresAt = $this->lease((int) $entry['grant_ttl']);
            // @infection-ignore-all; equivalent: PHP casts an array key to string on its own
            $holders[(string) $entry['token']] = [
                'waiter_type' => $entry['waiter_type'],
                'waiter_corr' => $entry['waiter_corr'],
                'expires_at' => $expiresAt,
            ];
            $this->grants[] = new GrantSlot(
                (string) ($this->vars[self::RESOURCE] ?? ''),
                (string) $entry['waiter_type'],
                (string) $entry['waiter_corr'],
                $expiresAt,
            );
        }

        $this->vars[self::HOLDERS] = $holders;
        $this->vars[self::QUEUE] = $queue;
    }

    /**
     * The expiry a lease of `$seconds` gets from now; a floor of one second so no lease is ever
     * born already dead, whatever a caller passed around the client's guards.
     */
    private function lease(int $seconds): string
    {
        return $this->now->addSeconds(max(1, $seconds))->toString();
    }

    /**
     * @return array<string, array{waiter_type: string, waiter_corr: string, expires_at: string}>
     */
    private function holders(): array
    {
        /** @var array<string, array{waiter_type: string, waiter_corr: string, expires_at: string}> */
        return is_array($this->vars[self::HOLDERS] ?? null) ? $this->vars[self::HOLDERS] : [];
    }

    /**
     * @return list<array{token: string, waiter_type: string, waiter_corr: string, grant_ttl: int, expires_at: string}>
     */
    private function queue(): array
    {
        /** @var list<array{token: string, waiter_type: string, waiter_corr: string, grant_ttl: int, expires_at: string}> */
        // Equivalent mutant on `array_values`: the twin of the one in `release()`, which normalizes
        // on the way in. Either alone keeps the list shape, so neither is observable by itself.
        // @infection-ignore-all; equivalent: the array_values twin argued just above
        return is_array($this->vars[self::QUEUE] ?? null) ? array_values($this->vars[self::QUEUE]) : [];
    }

    private function intVar(string $key): int
    {
        // The `?? 0` is the seed for a bag that predates a key, and the constructor path seeds every
        // key it reads, so no ledger built here reaches it. A rehydrated bag from an older shape
        // could, and nothing in this repository produces one. Left UN-ignored and un-tested on
        // purpose: a test would have to forge a bag the code cannot build, which proves the fixture
        // rather than the guard.
        return (int) ($this->vars[$key] ?? 0);
    }
}
