<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Message\Message;
use Storm\Saga\CircuitBreaker\BreakerState;
use Storm\Saga\CircuitBreaker\Dbal\DbalCircuitBreakerStorage;
use Storm\Saga\Exception\CorrelationAlreadyConsumed;
use Storm\Saga\Exception\CorrelationAlreadyOwned;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\Dbal\DbalWorkflowTimerStore;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Serializer\Exception\SerializationException;
use Storm\Serializer\MessageSerializer;

/**
 * The adapters' translation duty: a driver failure NEVER reaches the engine as a Doctrine type; it
 * surfaces as the port-owned SagaStorageFailure with the original chained as `previous`.
 */
final class StorageFailureTranslationTest extends TestCase
{
    #[Test]
    public function the_instance_store_wraps_a_driver_failure_with_its_cause_chained(): void
    {
        $driverFailure = $this->driverFailure('the wire dropped');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAssociative')
            ->with($this->anything(), ['type' => 'wf', 'corr' => 'c-1']) // the bound params, pinned
            ->willThrowException($driverFailure);

        try {
            new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));
            $this->fail('a driver failure must surface as SagaStorageFailure');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious());
            $this->assertStringContainsString('the wire dropped', $e->getMessage());
        }
    }

    #[Test]
    public function the_timer_store_wraps_a_driver_failure_too(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAllAssociative')
            ->with($this->anything(), ['type' => 'wf', 'corr' => 'c-1']) // the bound params, pinned
            ->willThrowException($this->driverFailure('gone'));

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowTimerStore($connection)->listFor(new WorkflowId('wf', 'c-1'));
    }

    #[Test]
    public function the_timer_store_wraps_a_corrupt_stored_instant_too(): void
    {
        // the arm the instance store's twin below already proves, on the two adapters that hydrate an
        // instant and had never been asked. A stubbed connection is what makes it reachable, so
        // calling it unreachable was reading the COLUMN's type as the only way a bad value arrives
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([[
            'id' => 1,
            'workflow_type' => 'wf',
            'correlation_id' => 'c-1',
            'state_key' => 'await',
            'kind' => 'timeout',
            'fire_at' => 'not-a-timestamp',
        ]]);

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowTimerStore($connection)->listFor(new WorkflowId('wf', 'c-1'));
    }

    #[Test]
    public function the_instance_store_hydrates_the_pause_reason_it_read(): void
    {
        // the read half of the store's contract, which the unit suite held nowhere: every fixture here
        // stored a NULL pause reason, so the ternary that guards it could hand the null branch and the
        // value branch to each other and answer the same. An operator reads this string to know why a
        // saga stopped.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->storedRow(pausedReason: 'frozen by ops'));

        $row = new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));

        $this->assertNotNull($row);
        $this->assertSame('frozen by ops', $row->pausedReason);
    }

    #[Test]
    public function the_breaker_storage_reads_a_healthy_row_back_as_its_snapshot(): void
    {
        // the HAPPY path of the same read, which nothing held: both refusal tests feed corruption, so
        // the coalesce that only throws when the enum lookup MISSES could throw unconditionally and no
        // test would notice, turning every stored breaker state into corruption.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'state' => 'open',
            'failures' => 3,
            'opened_at' => '2026-01-01T10:00:00.000000+00:00',
        ]);

        $snapshot = new DbalCircuitBreakerStorage($connection)->read('svc');

        $this->assertSame(BreakerState::Open, $snapshot->state);
        $this->assertSame(3, $snapshot->failures);
        $this->assertNotNull($snapshot->openedAt);
    }

    #[Test]
    public function the_breaker_storage_wraps_a_driver_failure_and_binds_the_key_it_was_asked_for(): void
    {
        // the DRIVER arm of the same union catch, which the two corruption cases cannot reach: they
        // both throw from inside the closure, so the arm that answers for the connection itself was
        // held by nothing. The bound params are pinned at the same boundary, a stub answering the
        // same row whatever the query asks for being unable to tell a bound key from a missing one.
        $driverFailure = $this->driverFailure('the wire dropped');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchAssociative')
            ->with($this->anything(), ['key' => 'svc'])
            ->willThrowException($driverFailure);

        try {
            new DbalCircuitBreakerStorage($connection)->read('svc');
            $this->fail('a driver failure must surface as SagaStorageFailure');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious());
        }
    }

    #[Test]
    public function the_breaker_storage_wraps_a_corrupt_stored_instant_too(): void
    {
        // the third adapter of the same duty, and the one where reading the epoch is worst: a null or
        // zero open instant is a cooldown that expired decades ago, so an open breaker would admit
        // its half-open probe at once. Its Redis twin refuses this in so many words; this backend
        // owed the same refusal and had only its driver-failure arm proven.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'state' => 'open',
            'failures' => 3,
            'opened_at' => 'yesterday',
        ]);

        $this->expectException(SagaStorageFailure::class);

        new DbalCircuitBreakerStorage($connection)->read('svc');
    }

    #[Test]
    public function a_corrupt_stored_bag_wraps_as_a_storage_failure(): void
    {
        // hydration is the storage's duty: invalid JSON in a stored bag is storage corruption, not a caller bug
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->storedRow(vars: '{not json'));

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));
    }

    #[Test]
    public function a_corrupt_stored_instant_wraps_as_a_storage_failure(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->storedRow(startedAt: 'not-a-timestamp'));

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));
    }

    #[Test]
    public function a_stored_row_hydrates_completely_and_typed(): void
    {
        // every bag round-trips with its values, and the rollback log as typed records, not raw arrays
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->storedRow(
            vars: '{"amount":42}',
            compensations: '[{"step":"charge","status":"pending","confirmed":true,"degraded":false,"reason":null,"at":null}]',
        ));

        $row = new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));

        $this->assertNotNull($row);
        $this->assertSame('wf', $row->workflowType);
        $this->assertSame('c-1', $row->correlationId);
        $this->assertSame('await', $row->stateKey);
        $this->assertNull($row->waivedAt); // NULL stays null, never coerced into an instant
        $this->assertSame(['amount' => 42], $row->vars);
        $this->assertSame(['attempts' => ['n' => 2, 'since' => null]], $row->retries); // the legacy bare count hydrates as a windowless ledger
        $this->assertSame(['actor' => 'cli'], $row->context);
        $this->assertSame(7, $row->version);
        $this->assertSame(5, $row->definitionVersion); // the pinned version round-trips, distinct from the OCC version
        $this->assertSame(4, $row->stateVersion);      // the data-shape axis round-trips as its own value
        $this->assertSame(6, $row->retryTotal);        // the lifetime retry counter round-trips too
        $this->assertNotNull($row->startedAt);
        $this->assertCount(1, $row->compensations);
        $this->assertSame('charge', $row->compensations[0]->step);
        $this->assertTrue($row->compensations[0]->confirmed);
    }

    #[Test]
    public function a_pk_violation_on_create_translates_to_a_storage_failure_not_correlation_ownership(): void
    {
        // the UQ branch, where workflow_instances_correlation_uq maps to CorrelationAlreadyOwned, is the correlation-claim
        // race, integration-tested in CorrelationOwnershipTest. A UQ violation whose message names a DIFFERENT
        // constraint, the PK, a same-type create-vs-create race, falls through to the generic storage failure.
        $pkViolation = $this->uniqueViolation('duplicate key value violates unique constraint "workflow_instances_pkey"');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willThrowException($pkViolation);

        try {
            new DbalWorkflowInstanceStore($connection)->create($this->newRow());
            $this->fail('a PK violation must translate to SagaStorageFailure');
        } catch (CorrelationAlreadyOwned) {
            $this->fail('a PK violation must NOT be read as a correlation-ownership conflict');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($pkViolation, $e->getPrevious()); // the driver exception is the cause
        }
    }

    #[Test]
    public function a_claim_violation_on_create_translates_to_correlation_consumption(): void
    {
        // the THIRD branch, and the one the ordering exists for: the instance row lands, then the durable
        // claim is refused by the REJECT index because this correlation already spent its run. A living
        // duplicate never reaches here; it trips workflow_instances_correlation_uq first and reads as
        // OWNED. The composite pk is a different failure entirely, a numbering race that retrying fixes,
        // and stays a storage failure.
        $claimViolation = $this->uniqueViolation('duplicate key value violates unique constraint "workflow_correlations_spent_uq"');
        $matcher = $this->exactly(2);
        $connection = $this->createMock(Connection::class);
        $connection->expects($matcher)->method('executeStatement')->willReturnCallback(
            function () use ($matcher, $claimViolation): int {
                if ($matcher->numberOfInvocations() === 2) {
                    throw $claimViolation;
                }

                return 1; // the instance INSERT lands; the claim behind it is what refuses
            },
        );

        $this->expectException(CorrelationAlreadyConsumed::class);

        new DbalWorkflowInstanceStore($connection)->create($this->newRow());
    }

    #[Test]
    public function a_generic_create_failure_translates_to_a_storage_failure(): void
    {
        // create()'s SECOND catch: a non-unique driver failure, a connection drop mid-INSERT, not a
        // constraint, is not the correlation race; it surfaces as the generic storage failure.
        $driverFailure = $this->driverFailure('the insert lost the wire');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willThrowException($driverFailure);

        try {
            new DbalWorkflowInstanceStore($connection)->create($this->newRow());
            $this->fail('a driver failure on create must surface as SagaStorageFailure');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious());
        }
    }

    #[Test]
    public function the_outbox_writer_wraps_a_write_failure(): void
    {
        // the message serializes fine, stubbed; the failure is the durable insert, so the writer owes
        // the same translation: the relay/engine never sees a Doctrine type.
        $driverFailure = $this->driverFailure('the outbox insert failed');
        $serializer = $this->createStub(MessageSerializer::class);
        $serializer->method('serialize')->willReturn(['header' => ['h' => 1], 'content' => ['c' => 2]]);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willThrowException($driverFailure);

        try {
            new DbalWorkflowOutboxWriter($connection, $serializer)->write(new WorkflowId('wf', 'c-1'), new Message(new stdClass), 'charge', 0, 1);
            $this->fail('a driver failure on the outbox insert must surface as SagaStorageFailure');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious());
        }
    }

    #[Test]
    public function the_outbox_writer_wraps_a_cancel_failure(): void
    {
        // cancelPending issues no serialize, only the bulk UPDATE; a driver failure there translates too.
        $driverFailure = $this->driverFailure('the cancel update failed');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willThrowException($driverFailure);

        try {
            new DbalWorkflowOutboxWriter($connection, $this->createStub(MessageSerializer::class))->cancelPending(new WorkflowId('wf', 'c-1'));
            $this->fail('a driver failure on cancelPending must surface as SagaStorageFailure');
        } catch (SagaStorageFailure $e) {
            $this->assertSame($driverFailure, $e->getPrevious());
        }
    }

    #[Test]
    public function a_correlation_uq_violation_on_create_translates_to_correlation_ownership(): void
    {
        // the branch the PK test does NOT take: a violation naming workflow_instances_correlation_uq is the
        // cross-type correlation claim, yielding CorrelationAlreadyOwned, the unit-side of the integration ownership test.
        $uq = $this->uniqueViolation('duplicate key value violates unique constraint "workflow_instances_correlation_uq"');
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->willThrowException($uq);

        $this->expectException(CorrelationAlreadyOwned::class);

        new DbalWorkflowInstanceStore($connection)->create($this->newRow());
    }

    #[Test]
    public function an_unencodable_create_bag_wraps_as_a_storage_failure(): void
    {
        // a JSON-encode failure on a bag, invalid UTF-8, is a codec failure INSIDE create()'s try; it
        // crosses as SagaStorageFailure, never a raw JsonException, and the executeStatement is never reached.
        $row = new WorkflowInstanceRow('transfer', 'c-1', 'await', WorkflowStatus::Running, ['bad' => "\xB1\x31"]);

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowInstanceStore($this->createStub(Connection::class))->create($row);
    }

    #[Test]
    public function an_unencodable_outbox_message_wraps_as_a_storage_failure(): void
    {
        // the writer json-encodes header/content before the insert; an invalid-UTF-8 bag throws a
        // JsonException inside the try, translated to SagaStorageFailure, same translation as a driver failure.
        $serializer = $this->createStub(MessageSerializer::class);
        $serializer->method('serialize')->willReturn(['header' => ['bad' => "\xB1\x31"], 'content' => []]);

        $this->expectException(SagaStorageFailure::class);

        new DbalWorkflowOutboxWriter($this->createStub(Connection::class), $serializer)->write(new WorkflowId('wf', 'c-1'), new Message(new stdClass), 'charge', 0, 1);
    }

    #[Test]
    public function the_outbox_writer_conserves_a_serialization_failure_instead_of_wrapping_it(): void
    {
        // serialize() runs BEFORE the writer's try: a non-serializable command is a wiring bug, not a storage
        // failure, so its SerializationException escapes as itself and the insert is never reached, the
        // conservation dual of the driver/codec wrapping above.
        $serializer = $this->createStub(MessageSerializer::class);
        $serializer->method('serialize')->willThrowException(SerializationException::notSerializablePayload(stdClass::class));
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(SerializationException::class);

        new DbalWorkflowOutboxWriter($connection, $serializer)->write(new WorkflowId('wf', 'c-1'), new Message(new stdClass), 'charge', 0, 1);
    }

    /**
     * A driver UQ violation whose surfaced message is exactly `$message`, carrying no
     * `correlation_uq` substring, so the `create()` catch's `str_contains` branch picks the generic
     * storage failure.
     */
    private function uniqueViolation(string $message): UniqueConstraintViolationException
    {
        $driverException = new class($message) extends AbstractException {};

        return new UniqueConstraintViolationException($driverException, null);
    }

    private function newRow(): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('transfer', 'c-1', 'await', WorkflowStatus::Running, [], [], [], 0, null, []);
    }

    #[Test]
    public function a_stored_waive_stamp_hydrates_as_an_instant(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->storedRow(waivedAt: '2026-07-02 10:00:00.000000+00'));

        $row = new DbalWorkflowInstanceStore($connection)->find(new WorkflowId('wf', 'c-1'));

        $this->assertNotNull($row);
        $this->assertNotNull($row->waivedAt); // the durable waive trace round-trips typed
    }

    /**
     * @return array<string, string|int|null>
     */
    private function storedRow(string $vars = '{}', string $startedAt = '2026-06-11 10:00:00.000000+00', string $compensations = '[]', ?string $waivedAt = null, ?string $pausedReason = null, ?string $parked = null): array
    {
        return [
            'workflow_type' => 'wf', 'correlation_id' => 'c-1', 'state_key' => 'await', 'status' => 'running',
            'vars' => $vars, 'retries' => '{"attempts":2}', 'compensations' => $compensations, 'context' => '{"actor":"cli"}',
            'version' => 7, 'generation' => 1, 'definition_version' => 5, 'state_version' => 4, 'retry_total' => 6, 'retimes' => 0, 'arms' => '{}', 'families' => '{}', 'parked' => $parked, 'started_at' => $startedAt,
            'waived_at' => $waivedAt, 'paused_at' => null, 'paused_reason' => $pausedReason,
        ];
    }

    private function driverFailure(string $message): DbalException&RuntimeException
    {
        return new class($message) extends RuntimeException implements DbalException {};
    }
}
