<?php

declare(strict_types=1);

namespace Storm\Saga\CircuitBreaker\Redis;

use Redis;
use RedisException;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\CircuitBreaker\BreakerSnapshot;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\CircuitBreakerStorage;
use Storm\Saga\Exception\SagaStorageFailure;

/**
 * `CircuitBreakerStorage` over one Redis hash per key, the opt-in swap when the guarded resource is
 * called often enough that a Postgres row on every outcome is the cost that hurts.
 *
 * One hash holds `state`, `failures` and `opened_at`; a missing hash, or a missing field within it,
 * reads as Closed with zero failures, so a breaker costs nothing until it first fails. A field that is
 * PRESENT but not what the writes store refuses as `SagaStorageFailure`, never defaults: a defaulted
 * read would admit exactly the call the breaker was holding back. Both writes are
 * Lua scripts, which Redis runs to completion against a single-threaded server: the increment and the
 * trip decided from its result cannot interleave, the same guarantee `INSERT … ON CONFLICT` buys on
 * Postgres, bought differently.
 *
 * `opened_at` is stamped from the REDIS server's own `TIME`, never the caller's clock, for the reason
 * the Postgres adapter stamps `clock_timestamp()`: the breaker is shared, so the cooldown must be
 * measured against one clock rather than against whichever worker happened to trip it. It is stored as
 * `seconds.microseconds` because the Lua sandbox has no date formatter, and read back through
 * `PointInTime::createFromTimestamp()`, which renormalizes it to the canonical UTC shape.
 *
 * No key ever expires, deliberately, and this is the trade to know: a resource that fails twice under a
 * threshold of three keeps those two failures until it succeeds. A TTL would decay the count, which is a
 * different breaker, one that forgives with time; the Postgres adapter does not forgive either, and two
 * adapters of one port must not answer differently. What Redis costs instead is one small hash per
 * resource key that outlives its usefulness, bounded by how many keys the app declares.
 */
final readonly class RedisCircuitBreakerStorage implements CircuitBreakerStorage
{
    /**
     * Reset to Closed only when there is something to reset. The healthy path, a success on a breaker
     * already Closed with no failures, is the common one and writes NOTHING: Redis holds no row lock to
     * contend for, but a blind `HSET` per successful call would still amplify into the AOF and to every
     * replica for a value that did not change.
     */
    private const string RESET = <<<'LUA'
        local state = redis.call('HGET', KEYS[1], 'state')
        local failures = tonumber(redis.call('HGET', KEYS[1], 'failures')) or 0
        if failures == 0 and (state == false or state == ARGV[1]) then
            return 0
        end
        redis.call('HSET', KEYS[1], 'state', ARGV[1], 'failures', 0)
        redis.call('HDEL', KEYS[1], 'opened_at')
        return 1
        LUA;

    /**
     * Increment, then trip at the threshold, in one indivisible run. `HINCRBY` mints the hash when the
     * key is unknown, so a first failure needs no separate create, and the stamp is re-taken on every
     * trip, which restarts the cooldown exactly as a re-open should.
     */
    private const string COUNT_AND_TRIP = <<<'LUA'
        local failures = redis.call('HINCRBY', KEYS[1], 'failures', 1)
        if failures >= tonumber(ARGV[1]) then
            local now = redis.call('TIME')
            redis.call('HSET', KEYS[1], 'state', ARGV[2], 'opened_at', now[1] .. '.' .. string.format('%06d', now[2]))
        end
        return failures
        LUA;

    /**
     * @param  string  $prefix  namespaces every breaker key, so a shared Redis holding cache, sessions
     *                          and locks cannot collide with a resource key an app chose freely
     */
    public function __construct(
        private Redis $redis,
        private string $prefix = 'storm:circuit_breaker:',
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when Redis is unreachable, reports a failed read, or holds a stored
     *                            field the writes could never have produced
     */
    public function read(string $key): BreakerSnapshot
    {
        try {
            $hash = $this->redis->hMGet($this->prefix.$key, ['state', 'failures', 'opened_at']);
        } catch (RedisException $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        // the same discipline eval() applies below: phpredis reports a failed HMGET, a key of the
        // wrong type for instance, by returning false and arming getLastError()
        if (! is_array($hash)) {
            throw SagaStorageFailure::unavailable(new RedisException($this->redis->getLastError() ?? 'the circuit-breaker hash could not be read'));
        }

        $state = $hash['state'] ?? false;
        $failures = $hash['failures'] ?? false;
        $openedAt = $hash['opened_at'] ?? false;

        // a missing field defaults, a present one is validated before the snapshot trusts it; the
        // message names the raw key, since corruption on a shared Redis is diagnosed server-side
        if (is_string($state) && BreakerState::tryFrom($state) === null) {
            throw SagaStorageFailure::corrupted(sprintf("state '%s' under '%s' is not a breaker state", $state, $this->prefix.$key));
        }

        if (is_string($failures) && ! ctype_digit($failures)) {
            throw SagaStorageFailure::corrupted(sprintf("failure count '%s' under '%s' is not a counter", $failures, $this->prefix.$key));
        }

        if (is_string($openedAt) && (! is_numeric($openedAt) || ! is_finite((float) $openedAt))) {
            throw SagaStorageFailure::corrupted(sprintf("open instant '%s' under '%s' is not a unix timestamp", $openedAt, $this->prefix.$key));
        }

        try {
            return new BreakerSnapshot(
                is_string($state) ? BreakerState::from($state) : BreakerState::Closed,
                is_string($failures) ? (int) $failures : 0,
                is_string($openedAt) ? PointInTime::createFromTimestamp((float) $openedAt) : null,
            );
        } catch (InvalidDateTimeException $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when Redis is unreachable or the script fails
     */
    public function recordSuccess(string $key): void
    {
        $this->eval(self::RESET, $key, [BreakerState::Closed->value]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when Redis is unreachable or the script fails
     */
    public function recordFailure(string $key, int $threshold): void
    {
        $this->eval(self::COUNT_AND_TRIP, $key, [(string) $threshold, BreakerState::Open->value]);
    }

    /**
     * Run a script on the one prefixed key, and refuse a silent no-op: phpredis reports a script error by
     * returning false and arming `getLastError()`, so an unchecked call would let a broken script read as
     * a breaker that simply counted nothing.
     *
     * @param  list<string>  $args
     *
     * @throws SagaStorageFailure
     */
    private function eval(string $script, string $key, array $args): void
    {
        try {
            $result = $this->redis->eval($script, [$this->prefix.$key, ...$args], 1);
        } catch (RedisException $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        if ($result === false) {
            throw SagaStorageFailure::unavailable(new RedisException($this->redis->getLastError() ?? 'the circuit-breaker script failed'));
        }
    }
}
