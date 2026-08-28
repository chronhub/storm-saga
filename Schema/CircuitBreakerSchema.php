<?php

declare(strict_types=1);

namespace Storm\Saga\Schema;

/**
 * Raw PostgreSQL DDL for `circuit_breaker`, the shared breaker state.
 *
 * One row per dev-chosen resource `key`. `state` is `closed` or `open`, `half_open` being computed
 * and never stored; `failures` is the consecutive count; `opened_at` stamps the trip and drives the
 * cooldown. `DbalCircuitBreakerStorage` mutates the row with an atomic `INSERT … ON CONFLICT DO
 * UPDATE`, which is what lets concurrent workers share one breaker safely.
 *
 * @see \Storm\Saga\CircuitBreaker\Dbal\DbalCircuitBreakerStorage
 */
final class CircuitBreakerSchema
{
    /**
     * @return list<string>
     */
    public static function up(): array
    {
        return [
            /** @lang PostgreSQL */
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS circuit_breaker (
                    key        text PRIMARY KEY,
                    state      text           NOT NULL DEFAULT 'closed',
                    failures   integer        NOT NULL DEFAULT 0,
                    opened_at  timestamptz(6),
                    CONSTRAINT circuit_breaker_state_chk CHECK (state IN ('closed', 'open')),
                    CONSTRAINT circuit_breaker_failures_chk CHECK (failures >= 0)
                )
                SQL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function down(): array
    {
        return [
            /** @lang PostgreSQL */
            'DROP TABLE IF EXISTS circuit_breaker',
        ];
    }
}
