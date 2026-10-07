<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Outbox;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Exception\InvalidRedriveBatch;
use Storm\Saga\Exception\InvalidWorkflowId;
use Storm\Saga\Outbox\RedriveBatchScope;
use Storm\Saga\Outbox\RedriveCandidate;

/**
 * The batch redrive's value objects refuse a scope or a candidate that breaks its invariants, so
 * a caller bypassing the console never reaches storage with one.
 */
final class RedriveBatchScopeTest extends TestCase
{
    #[Test]
    public function a_scope_at_its_bounds_is_accepted(): void
    {
        $before = new DateTimeImmutable('2026-01-04T00:00:00+00:00');
        $scope = new RedriveBatchScope('payment', RedriveBatchScope::MAX_LIMIT, $before, new DateTimeImmutable('2026-01-03T23:59:59.999999+00:00'), PHP_INT_MAX);

        $this->assertSame(1000, $scope->limit);
        $this->assertSame(PHP_INT_MAX, $scope->afterId);
        $this->assertSame(1, new RedriveBatchScope('payment', 1, $before)->limit);
    }

    #[Test]
    public function a_scope_without_a_cursor_starts_before_the_first_row(): void
    {
        // ids are strictly greater than the cursor, and the first row's id is 1: a default of 1
        // would skip it on every fresh redrive
        $this->assertSame(0, new RedriveBatchScope('payment', 1, new DateTimeImmutable('2026-01-04T00:00:00+00:00'))->afterId);
    }

    /**
     * @return iterable<string, array{string, int, string, ?string, int}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'empty type' => ['', 1, '2026-01-04T00:00:00Z', null, 0];
        yield 'blank type' => ['  ', 1, '2026-01-04T00:00:00Z', null, 0];
        yield 'padded type' => [' payment', 1, '2026-01-04T00:00:00Z', null, 0];
        yield 'zero limit' => ['payment', 0, '2026-01-04T00:00:00Z', null, 0];
        yield 'limit past the ceiling' => ['payment', 1001, '2026-01-04T00:00:00Z', null, 0];
        yield 'negative cursor' => ['payment', 1, '2026-01-04T00:00:00Z', null, -1];
        yield 'window of one instant' => ['payment', 1, '2026-01-04T00:00:00Z', '2026-01-04T00:00:00Z', 0];
        yield 'inverted window across offsets' => ['payment', 1, '2026-01-04T00:00:00Z', '2026-01-04T01:30:00+01:00', 0];
    }

    #[Test]
    #[DataProvider('invalidScopes')]
    public function a_scope_breaking_an_invariant_is_refused(string $type, int $limit, string $before, ?string $since, int $afterId): void
    {
        $this->expectException(InvalidRedriveBatch::class);

        new RedriveBatchScope($type, $limit, new DateTimeImmutable($before), $since === null ? null : new DateTimeImmutable($since), $afterId);
    }

    #[Test]
    public function a_candidate_needs_a_positive_id_and_a_valid_saga_identity(): void
    {
        $candidate = new RedriveCandidate(7, 'payment', 'c-7');
        $this->assertSame('payment', $candidate->saga()->workflowType);
        $this->assertSame('c-7', $candidate->saga()->correlationId);

        try {
            new RedriveCandidate(0, 'payment', 'c-0');
            $this->fail('A candidate without a positive id must be refused.');
        } catch (InvalidRedriveBatch) {
        }

        $this->expectException(InvalidWorkflowId::class);
        new RedriveCandidate(1, 'payment', ' ');
    }
}
