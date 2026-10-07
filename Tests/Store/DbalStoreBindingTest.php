<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Message\Message;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Outbox\CommandPurpose;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;
use Storm\Serializer\MessageSerializer;

/**
 * The DBAL adapters bind the RIGHT column to the RIGHT placeholder, the silent contract between the
 * INSERT/UPDATE text and its parameter array. A swapped or dropped binding corrupts the row without an
 * error, so each placeholder is pinned to its value here; the failure paths live in
 * StorageFailureTranslationTest.
 */
final class DbalStoreBindingTest extends TestCase
{
    #[Test]
    public function the_instance_store_binds_every_create_column(): void
    {
        $comp = CompensationRecord::pending('charge', '2024-01-01T00:00:00Z');
        $row = new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, ['a' => 1], ['charge' => ['n' => 2, 'since' => null]], ['actor' => 'cli'], 7, null, [$comp], definitionVersion: 3, retryTotal: 4, stateVersion: 2);

        // a create writes the PAIR: the living row, then the durable claim on its correlation. Both are
        // pinned here because a dropped binding on either half corrupts silently: a claim carrying the
        // wrong type or version misreports which run ever held the correlation.
        $matcher = $this->exactly(2);
        $connection = $this->createMock(Connection::class);
        $connection->expects($matcher)->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params, array $types) use ($matcher, $comp): int {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertSame([
                        'type' => 'payment',
                        'corr' => 'o-1',
                        'state' => 'charge',
                        'status' => WorkflowStatus::Running->value,
                        'vars' => json_encode(['a' => 1], JSON_THROW_ON_ERROR),
                        'retries' => json_encode(['charge' => ['n' => 2, 'since' => null]], JSON_THROW_ON_ERROR),
                        'compensations' => json_encode([$comp->toArray()], JSON_THROW_ON_ERROR),
                        'context' => json_encode(['actor' => 'cli'], JSON_THROW_ON_ERROR),
                        'version' => 7,
                        'retry_total' => 4, // the lifetime retry counter, mutable, bound on INSERT and UPDATE
                        'retimes' => 0, // the lifetime retime counter, the retry_total twin
                        'arms' => '{}', // the per-join arrival ledgers, empty as the object shape
                        'families' => '{}', // the per-family expected member counts, same discipline
                        'parked' => null, // the crossing a family gate owes back, absent on a birth and on every row outside that wait
                        'global_deadline_consumed_at' => null,
                        'waived_at' => null, // the waived-cap trace, stamped by the waive, threaded ever after
                        'state_version' => 2, // the data-shape axis: birth stamp here, the migration chain's bump on UPDATE
                        'started_at' => null,
                        'def_version' => 3, // the pinned version, bound from the row, distinct from the OCC version
                        'generation' => 1, // the run number the claim handed back; 1 under the default policy
                    ], $params),
                    default => $this->assertSame([
                        'corr' => 'o-1',
                        'generation' => 1,
                        'type' => 'payment',
                        'def_version' => 3, // the claim records WHICH shape the run was born under
                        'reuse' => 'reject', // the policy rides ONTO the row: it is what the partial index reads
                        'started_at' => null, // the claim is born with the instance, so it shares its instant
                    ], $params),
                };

                $this->assertStringContainsString(
                    $matcher->numberOfInvocations() === 1 ? 'INSERT INTO workflow_instances' : 'INSERT INTO workflow_correlations',
                    $sql,
                );
                // the whole type map, not one key: a dropped `version` entry would bind the OCC anchor
                // as a string and the row would still write, silently
                $this->assertSame(
                    $matcher->numberOfInvocations() === 1
                        ? ['version' => ParameterType::INTEGER, 'def_version' => ParameterType::INTEGER, 'state_version' => ParameterType::INTEGER, 'retry_total' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER]
                        : ['def_version' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER],
                    $types,
                );

                return 1;
            },
        );

        new DbalWorkflowInstanceStore($connection)->create($row);
    }

    #[Test]
    public function the_update_binds_its_integer_columns(): void
    {
        // the OCC anchor rides the WHERE clause, so binding `version` as a string would compare a text
        // literal against an integer column, the update silently matching nothing, read as a conflict
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            $this->stringContains('UPDATE workflow_instances'),
            $this->anything(),
            ['version' => ParameterType::INTEGER, 'retry_total' => ParameterType::INTEGER, 'state_version' => ParameterType::INTEGER],
        )->willReturn(1);

        new DbalWorkflowInstanceStore($connection)->update($this->row());
    }

    #[Test]
    public function the_update_parameters_bind_every_placeholder_the_update_names(): void
    {
        // the atomic step writer embeds `UPDATE_SQL` and binds it through this public pair, from
        // outside the store; a placeholder the parameters miss fails only against a real server
        preg_match_all('/(?<!:):([a-z_]+)/', DbalWorkflowInstanceStore::UPDATE_SQL, $placeholders);
        $parameters = DbalWorkflowInstanceStore::updateParameters($this->row());

        $this->assertNotEmpty($placeholders[1]);
        foreach ($placeholders[1] as $placeholder) {
            $this->assertArrayHasKey($placeholder, $parameters);
        }
    }

    #[Test]
    public function an_update_that_matches_no_row_is_a_concurrency_conflict(): void
    {
        // the OCC verdict reads the affected-row COUNT: zero means the stored version moved on, so a
        // competing step won. Any other reading of that count turns a lost race into a silent no-op.
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $this->expectException(StaleWorkflowInstance::class);

        new DbalWorkflowInstanceStore($connection)->update($this->row());
    }

    #[Test]
    public function the_generation_is_the_next_one_after_the_highest_claimed(): void
    {
        // the numbering read, pinned on a non-zero result: with the mock's default null every arithmetic
        // mutation of `1 + (int) …` lands back on 1, so only a correlation that already HAS claims can
        // tell `1 +` from `1 -`, or prove the cast is what turns the driver's string into an int.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('4'); // the driver hands back a string
        $seen = [];
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params) use (&$seen): int {
                $seen[] = $params['generation'];

                return 1;
            },
        );

        new DbalWorkflowInstanceStore($connection)->create($this->row());

        $this->assertSame([5, 5], $seen, 'the instance row and its claim carry the SAME next generation');
    }

    #[Test]
    public function the_numbering_read_is_scoped_to_this_correlation(): void
    {
        // without the binding the read would take the highest generation of the WHOLE table, so every
        // saga would inherit the run number of the busiest correlation
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->stringContains('FROM workflow_correlations'), ['corr' => 'o-1'])
            ->willReturn('0');
        $connection->method('executeStatement')->willReturn(1);

        $generation = new DbalWorkflowInstanceStore($connection)->create($this->row());

        $this->assertSame(1, $generation, 'a correlation with no prior claim starts at run 1');
    }

    private function row(): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, definitionVersion: 3);
    }

    #[Test]
    public function the_outbox_writer_binds_the_insert_columns(): void
    {
        $serializer = $this->createStub(MessageSerializer::class);
        $serializer->method('serialize')->willReturn(['header' => ['h' => 1], 'content' => ['c' => 2]]);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            $this->stringContains('INSERT INTO workflow_outbox'),
            [
                'type' => 'transfer',
                'corr' => 't-1',
                'bus' => 'storm.command.bus',
                'header' => json_encode(['h' => 1], JSON_THROW_ON_ERROR),
                'content' => json_encode(['c' => 2], JSON_THROW_ON_ERROR),
                'from_state' => 'charge', // the provenance pair, the settle's pairing input
                'at_version' => 0,
                'generation' => 1, // the seal: which RUN of this correlation issued the command
                'purpose' => 'compensation', // the purpose handed in, never a constant: what the abort's recall reads
                'effect_group' => null, // the targeted recall's key, null for the ungrouped common case
            ],
            ['at_version' => ParameterType::INTEGER, 'generation' => ParameterType::INTEGER],
        );

        new DbalWorkflowOutboxWriter($connection, $serializer)->write(new WorkflowId('transfer', 't-1'), new Message(new stdClass), 'charge', 0, 1, CommandPurpose::Compensation);
    }

    #[Test]
    public function the_outbox_writer_binds_the_cancel_columns_and_returns_the_count(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            $this->stringContains('UPDATE workflow_outbox'),
            ['type' => 'transfer', 'corr' => 't-1', 'generation' => 2],
            ['generation' => ParameterType::INTEGER],
        )->willReturn(3);

        $recalled = new DbalWorkflowOutboxWriter($connection, $this->createStub(MessageSerializer::class))->cancelPending(new WorkflowId('transfer', 't-1'), 2, []);

        $this->assertSame(3, $recalled); // the (int) cast on the affected-rows count
    }

    #[Test]
    public function the_outbox_writer_binds_each_spared_entry_by_state_and_arm(): void
    {
        // one exclusion per undone entry, its group compared null-safely: an ungrouped entry spares the
        // ungrouped rows of its state, an arm's entry the rows of that arm alone
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            $this->logicalAnd(
                $this->stringContains(' AND NOT (issued_from_state = :spared_state_0 AND effect_group IS NOT DISTINCT FROM :spared_group_0)'),
                $this->stringContains(' AND NOT (issued_from_state = :spared_state_1 AND effect_group IS NOT DISTINCT FROM :spared_group_1)'),
            ),
            ['type' => 'transfer', 'corr' => 't-1', 'generation' => 2, 'spared_state_0' => 'charge', 'spared_group_0' => null, 'spared_state_1' => 'race', 'spared_group_1' => 'left'],
            ['generation' => ParameterType::INTEGER],
        )->willReturn(1);
        $spared = [
            CompensationRecord::pending('charge')->settle(CompensationStatus::Compensated),
            CompensationRecord::forArm('race', 'left', CompensationStatus::Compensated, false),
        ];

        $recalled = new DbalWorkflowOutboxWriter($connection, $this->createStub(MessageSerializer::class))->cancelPending(new WorkflowId('transfer', 't-1'), 2, $spared);

        $this->assertSame(1, $recalled);
    }

    #[Test]
    public function the_outbox_writer_locks_the_unclaimed_rows_then_reads_every_marker_as_a_claim(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $params, array $types) use (&$calls): array {
                $calls[] = [$sql, $params, $types];

                return count($calls) === 1 ? [7] : [null, '2026-09-24 10:00:00+00'];
            },
        );

        $claims = new DbalWorkflowOutboxWriter($connection, $this->createStub(MessageSerializer::class))->forwardClaims(new WorkflowId('transfer', 't-1'), 2, 'quote', null);

        $this->assertSame([false, true], $claims); // a NULL marker was never claimed, any stamp was
        [$lock, $read] = $calls;
        // the lock takes the unclaimed rows alone and never skips; the read covers every row, lock-free
        $this->assertStringContainsString('claimed_until IS NULL', $lock[0]);
        $this->assertStringContainsString('FOR UPDATE', $lock[0]);
        $this->assertStringNotContainsString('SKIP LOCKED', $lock[0]);
        $this->assertStringNotContainsString('claimed_until IS NULL', $read[0]);
        $this->assertStringNotContainsString('FOR UPDATE', $read[0]);
        foreach ([$lock, $read] as [$sql, $params, $types]) {
            $this->assertStringContainsString("purpose = 'forward'", $sql);
            $this->assertStringContainsString('ORDER BY id', $sql);
            $this->assertSame(['type' => 'transfer', 'corr' => 't-1', 'generation' => 2, 'from_state' => 'quote', 'effect_group' => null], $params);
            $this->assertSame(['generation' => ParameterType::INTEGER], $types);
        }
    }

    #[Test]
    public function the_outbox_writer_binds_the_proving_recall_columns_and_returns_the_count(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with(
            $this->stringContains('UPDATE workflow_outbox'),
            ['type' => 'transfer', 'corr' => 't-1', 'effect_group' => 'beta', 'generation' => 2, 'from_state' => 'quote'],
            ['generation' => ParameterType::INTEGER],
        )->willReturn(1);

        $recalled = new DbalWorkflowOutboxWriter($connection, $this->createStub(MessageSerializer::class))->recallUndispatched(new WorkflowId('transfer', 't-1'), 2, 'quote', 'beta');

        $this->assertSame(1, $recalled);
    }
}
