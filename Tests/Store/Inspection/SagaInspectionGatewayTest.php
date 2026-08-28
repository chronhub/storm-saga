<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store\Inspection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\FinalState;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The forensic read's own contract, over a recorded connection: WHAT each read asks the database
 * for, and what the rows become on the way out. Two halves, and the second is the one no gate could
 * see before, since a snapshot built from the wrong column, or carrying a driver's raw type instead
 * of the declared one, corrupts an operator's answer without an error anywhere.
 *
 * The rows here are deliberately typed the way a driver may hand them back rather than the way the
 * snapshot declares them, integers for counters and a raw value for a stamp: the casts are the
 * contract, so a fixture that pre-shapes its rows would assert nothing about them. The sibling of
 * `DbalStoreBindingTest`, whose subject is the write side's placeholders.
 *
 * What stays PostgreSQL's, and is not attempted here: that the SQL means what it says. This proves
 * the gateway asks for the right thing and reads the answer correctly, never that the server agrees.
 */
final class SagaInspectionGatewayTest extends TestCase
{
    /** @var list<array{sql: string, params: array<string, mixed>, types: array<string, mixed>}> */
    private array $reads = [];

    /** @var list<string> */
    private array $statements = [];

    #[Test]
    public function an_inspected_instance_hydrates_every_field_it_declares(): void
    {
        $gateway = $this->gateway([[$this->instanceRow()], [], [], []]);

        $snapshots = $gateway->inspect('corr-1', null);

        self::assertCount(1, $snapshots);
        $snapshot = $snapshots[0];
        self::assertSame('onboarding', $snapshot->workflowType);
        self::assertSame('await_kyc', $snapshot->stateKey);
        self::assertSame('running', $snapshot->status);
        self::assertSame(7, $snapshot->version);
        self::assertSame('2026-08-23 10:00:00+00', $snapshot->startedAt);
        self::assertSame('2026-08-23 10:05:00+00', $snapshot->updatedAt);
        self::assertSame(3, $snapshot->generation);
        self::assertSame(5, $snapshot->definitionVersion);
        self::assertSame(4, $snapshot->retryTotal);
        self::assertSame('2026-08-23 10:06:00+00', $snapshot->waivedAt);
        self::assertSame('identity', $snapshot->parentWorkflowType);
        self::assertSame('root-1', $snapshot->parentCorrelationId);
        self::assertSame('root-0', $snapshot->rootCorrelationId);
        self::assertSame(2, $snapshot->stateVersion);
        self::assertSame(6, $snapshot->retimes);
        self::assertSame('2026-08-23 10:07:00+00', $snapshot->pausedAt);
        self::assertSame('operator on call', $snapshot->pausedReason);
        self::assertTrue($snapshot->typePaused);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_inspected_instance_carries_the_declared_type_and_not_the_driver_s(): void
    {
        // every counter arrives as a string and every stamp as something that is not one, which is
        // what a driver is free to do: the snapshot's declared types are the gateway's promise, and
        // a dropped cast would hand an operator surface a value it cannot render
        $row = [...$this->instanceRow(), 'version' => '7', 'generation' => '3', 'definition_version' => '5',
            'retry_total' => '4', 'state_version' => '2', 'retimes' => '6', 'type_paused' => 1,
            'started_at' => 1_700_000_000, 'updated_at' => 1_700_000_001, 'waived_at' => 1_700_000_002,
            'parent_workflow_type' => 1_700_000_003, 'parent_correlation_id' => 1_700_000_004,
            'root_correlation_id' => 1_700_000_005, 'paused_at' => 1_700_000_006,
            'paused_reason' => 1_700_000_007];

        $snapshot = $this->gateway([[$row], [], [], []])->inspect('corr-1', null)[0];

        self::assertSame(7, $snapshot->version);
        self::assertSame(3, $snapshot->generation);
        self::assertSame(5, $snapshot->definitionVersion);
        self::assertSame(4, $snapshot->retryTotal);
        self::assertSame(2, $snapshot->stateVersion);
        self::assertSame(6, $snapshot->retimes);
        self::assertTrue($snapshot->typePaused);
        self::assertSame('1700000000', $snapshot->startedAt);
        self::assertSame('1700000001', $snapshot->updatedAt);
        self::assertSame('1700000002', $snapshot->waivedAt);
        self::assertSame('1700000003', $snapshot->parentWorkflowType);
        self::assertSame('1700000004', $snapshot->parentCorrelationId);
        self::assertSame('1700000005', $snapshot->rootCorrelationId);
        self::assertSame('1700000006', $snapshot->pausedAt);
        self::assertSame('1700000007', $snapshot->pausedReason);
    }

    #[Test]
    public function an_inspected_instance_keeps_its_absent_stamps_absent(): void
    {
        // the null side of every optional field: a flipped ternary would answer null where a value
        // stands and a value where none does, and only both directions asserted tell them apart
        $row = [...$this->instanceRow(), 'started_at' => null, 'updated_at' => null, 'waived_at' => null,
            'paused_at' => null, 'paused_reason' => null, 'parent_workflow_type' => null,
            'parent_correlation_id' => null, 'root_correlation_id' => null];

        $snapshot = $this->gateway([[$row], [], [], []])->inspect('corr-1', null)[0];

        self::assertNull($snapshot->startedAt);
        self::assertNull($snapshot->updatedAt);
        self::assertNull($snapshot->waivedAt);
        self::assertNull($snapshot->pausedAt);
        self::assertNull($snapshot->pausedReason);
        self::assertNull($snapshot->parentWorkflowType);
        self::assertNull($snapshot->parentCorrelationId);
        self::assertNull($snapshot->rootCorrelationId);
    }

    #[Test]
    #[Group('adversarial')]
    public function the_inspection_reads_one_repeatable_read_snapshot(): void
    {
        // the 1+2N reads are a single consistent picture on purpose: without the isolation statement
        // a saga advancing mid-inspection shows its new timers against its old state, which is the
        // one thing a forensic read must not do
        $this->gateway([[$this->instanceRow()], [], [], []])->inspect('corr-1', null);

        self::assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'], $this->statements);
    }

    #[Test]
    public function an_inspection_narrowed_to_a_type_binds_it_and_an_unnarrowed_one_does_not(): void
    {
        // the narrowing is APPENDED, and the whole query has to survive it: a clause that replaced
        // the statement instead of extending it would still contain the words asserted below
        $this->gateway([[], [], [], []])->inspect('corr-1', 'onboarding');
        self::assertStringStartsWith('SELECT i.workflow_type', $this->read(0)['sql']);
        self::assertStringContainsString('FROM workflow_instances i WHERE i.correlation_id = :corr AND i.workflow_type = :type', $this->read(0)['sql']);
        self::assertSame(['corr' => 'corr-1', 'type' => 'onboarding'], $this->read(0)['params']);

        $this->forgetReads();
        $this->gateway([[], [], [], []])->inspect('corr-2', null);
        self::assertStringNotContainsString('AND i.workflow_type', $this->read(0)['sql']);
        self::assertSame(['corr' => 'corr-2'], $this->read(0)['params']);
    }

    #[Test]
    public function the_satellite_reads_are_scoped_to_the_instance_they_belong_to(): void
    {
        // the timers and the outbox are per (type, correlation) and the children are per parent: a
        // dropped binding would hand one saga's forensics to another on the same correlation
        $this->gateway([[$this->instanceRow()], [], [], []])->inspect('corr-1', null);

        self::assertSame(['type' => 'onboarding', 'corr' => 'corr-1'], $this->read(1)['params']);
        self::assertSame(['type' => 'onboarding', 'corr' => 'corr-1'], $this->read(2)['params']);
        self::assertSame(['corr' => 'corr-1'], $this->read(3)['params']);
    }

    #[Test]
    public function armed_timers_hydrate_typed_and_keep_their_absent_fields_absent(): void
    {
        $timers = [
            ['id' => '12', 'kind' => 'timeout', 'state_key' => 'await_kyc', 'fire_at' => 1_700_000_001,
                'claimed_at' => 1_700_000_010, 'attempts' => '2',
                'parked_at' => 1_700_000_011, 'last_error' => 1_700_000_012],
            ['id' => 13, 'kind' => 'global', 'state_key' => 'await_kyc', 'fire_at' => 'later',
                'claimed_at' => null, 'attempts' => 0, 'parked_at' => null, 'last_error' => null],
        ];

        $snapshot = $this->gateway([[$this->instanceRow()], $timers, [], []])->inspect('corr-1', null)[0];

        self::assertCount(2, $snapshot->timers);
        self::assertSame(12, $snapshot->timers[0]->id);
        self::assertSame('1700000001', $snapshot->timers[0]->fireAt);
        self::assertSame('1700000010', $snapshot->timers[0]->claimedAt);
        self::assertSame(2, $snapshot->timers[0]->attempts);
        self::assertSame('1700000011', $snapshot->timers[0]->parkedAt);
        self::assertSame('1700000012', $snapshot->timers[0]->lastError);
        self::assertNull($snapshot->timers[1]->claimedAt);
        self::assertNull($snapshot->timers[1]->parkedAt);
        self::assertNull($snapshot->timers[1]->lastError);
    }

    #[Test]
    public function issued_commands_hydrate_typed_and_an_unreadable_evidence_reads_as_unknown(): void
    {
        // evidence drives whether a dead-lettered command may settle a saga, so a value this build
        // cannot name must degrade to Unknown rather than throw inside a forensic read
        $outbox = [
            ['status' => 'published', 'bus' => 'command', 'attempts' => '3', 'created_at' => 1_700_000_002,
                'last_error' => 1_700_000_020, 'issued_from_state' => 'charge', 'issued_at_version' => '9',
                'generation' => '2', 'command' => 1_700_000_021, 'message_id' => 1_700_000_022,
                'evidence' => 'uncommitted', 'effect_group' => 1_700_000_023],
            ['status' => 'pending', 'bus' => 'command', 'attempts' => 0, 'created_at' => 'now',
                'last_error' => null, 'issued_from_state' => 'charge', 'issued_at_version' => 1,
                'generation' => 1, 'command' => null, 'message_id' => null,
                'evidence' => 'a kind this build never heard of', 'effect_group' => null],
        ];

        $snapshot = $this->gateway([[$this->instanceRow()], [], $outbox, []])->inspect('corr-1', null)[0];

        self::assertCount(2, $snapshot->outbox);
        self::assertSame(3, $snapshot->outbox[0]->attempts);
        self::assertSame('1700000002', $snapshot->outbox[0]->createdAt);
        self::assertSame(9, $snapshot->outbox[0]->issuedAtVersion);
        self::assertSame(2, $snapshot->outbox[0]->generation);
        self::assertSame('1700000021', $snapshot->outbox[0]->command);
        self::assertSame('1700000022', $snapshot->outbox[0]->messageId);
        self::assertSame('1700000023', $snapshot->outbox[0]->effectGroup);
        self::assertSame('1700000020', $snapshot->outbox[0]->lastError);
        self::assertSame(EffectEvidence::Uncommitted, $snapshot->outbox[0]->evidence);
        self::assertNull($snapshot->outbox[1]->command);
        self::assertNull($snapshot->outbox[1]->messageId);
        self::assertNull($snapshot->outbox[1]->lastError);
        self::assertNull($snapshot->outbox[1]->effectGroup);
        self::assertSame(EffectEvidence::Unknown, $snapshot->outbox[1]->evidence);
    }

    #[Test]
    public function spawned_children_hydrate_typed(): void
    {
        $children = [['workflow_type' => 'kyc_review', 'correlation_id' => 'corr-1kyc', 'status' => 'completed']];

        $snapshot = $this->gateway([[$this->instanceRow()], [], [], $children])->inspect('corr-1', null)[0];

        self::assertCount(1, $snapshot->children);
        self::assertSame('kyc_review', $snapshot->children[0]->workflowType);
        self::assertSame('corr-1kyc', $snapshot->children[0]->correlationId);
        self::assertSame('completed', $snapshot->children[0]->status);
    }

    #[Test]
    public function a_listing_clamps_the_limit_it_was_asked_for(): void
    {
        // the ceiling is the caller's, not the database's: an operator asking for everything gets a
        // bounded answer, and one asking for nothing still gets a row rather than an empty page
        self::assertSame(SagaInspectionGateway::MAX_LIMIT, $this->gateway([[]])->list(limit: 10_000)->limit);
        self::assertSame(1, $this->gateway([[]])->list(limit: 0)->limit);
        self::assertSame(1, $this->gateway([[]])->list(limit: -5)->limit);
        self::assertSame(50, $this->gateway([[]])->list()->limit);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_listing_asks_for_one_row_past_its_cap_so_truncation_is_known(): void
    {
        // the probe row is the whole mechanism: it is what lets the answer SAY it was cut, without
        // paying a count(*) over the population. Asking for exactly the cap would make a full page
        // and a truncated one indistinguishable.
        $this->gateway([[]])->list(limit: 3);

        self::assertSame(4, $this->read(0)['params']['n']);
        self::assertSame(ParameterType::INTEGER, $this->read(0)['types']['n']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_listing_reports_truncation_only_past_the_cap_and_drops_the_probe_row(): void
    {
        // exactly at the cap is a COMPLETE answer; one past it is a cut one, and the extra row was
        // never a result, so it must not reach the caller
        $rows = array_map(static fn (int $i): array => [...self::summaryRow(), 'correlation_id' => 'c-'.$i], range(1, 3));

        $full = $this->gateway([$rows])->list(limit: 3);
        self::assertFalse($full->truncated);
        self::assertCount(3, $full->sagas);

        $this->forgetReads();
        $cut = $this->gateway([[...$rows, [...self::summaryRow(), 'correlation_id' => 'c-4']]])->list(limit: 3);
        self::assertTrue($cut->truncated);
        self::assertCount(3, $cut->sagas);
        self::assertSame(['c-1', 'c-2', 'c-3'], array_map(static fn ($s): string => $s->correlationId, $cut->sagas));
    }

    #[Test]
    public function a_listing_narrows_only_on_the_filters_it_was_given(): void
    {
        // the seam, not the word: the base query already carries a WHERE inside the type-freeze
        // EXISTS, so what says "unfiltered" is the instances table running straight into its ORDER BY
        $this->gateway([[]])->list();
        self::assertStringContainsString('FROM workflow_instances i ORDER BY', $this->read(0)['sql']);

        $this->forgetReads();
        $this->gateway([[]])->list(type: 'onboarding', status: WorkflowStatus::Halted, idleForSeconds: 90, waivedOnly: true);
        $sql = $this->read(0)['sql'];
        self::assertStringContainsString('i.workflow_type = :type', $sql);
        self::assertStringContainsString('i.status = :status', $sql);
        self::assertStringContainsString('make_interval(secs => :idle)', $sql);
        self::assertStringContainsString('i.waived_at IS NOT NULL', $sql);
        self::assertSame('onboarding', $this->read(0)['params']['type']);
        self::assertSame('halted', $this->read(0)['params']['status']);
        self::assertSame(90, $this->read(0)['params']['idle']);
        self::assertSame(ParameterType::INTEGER, $this->read(0)['types']['idle']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_listing_treats_an_empty_type_and_a_non_positive_idle_as_no_filter_at_all(): void
    {
        // an unfilled form field arrives as an empty string and a zero, not as null: narrowing on
        // either would answer nothing where the operator asked for everything
        $this->gateway([[]])->list(type: '', idleForSeconds: 0, waivedOnly: false);

        self::assertStringContainsString('FROM workflow_instances i ORDER BY', $this->read(0)['sql']);
        self::assertArrayNotHasKey('idle', $this->read(0)['params']);
        self::assertArrayNotHasKey('type', $this->read(0)['params']);

        $this->forgetReads();
        $this->gateway([[]])->list(idleForSeconds: -30);
        self::assertStringContainsString('FROM workflow_instances i ORDER BY', $this->read(0)['sql']);
    }

    #[Test]
    public function a_listed_saga_carries_the_declared_type_and_keeps_its_absent_stamps_absent(): void
    {
        $typed = [...self::summaryRow(), 'version' => '7', 'generation' => '3', 'definition_version' => '5',
            'retry_total' => '4', 'type_paused' => 1, 'started_at' => 1_700_000_030,
            'updated_at' => 1_700_000_031, 'waived_at' => 1_700_000_032,
            'parent_correlation_id' => 1_700_000_033, 'paused_at' => 1_700_000_034];
        $bare = [...self::summaryRow(), 'started_at' => null, 'updated_at' => null, 'waived_at' => null,
            'parent_correlation_id' => null, 'paused_at' => null, 'type_paused' => false];

        $sagas = $this->gateway([[$typed, $bare]])->list()->sagas;

        self::assertSame(7, $sagas[0]->version);
        self::assertSame(3, $sagas[0]->generation);
        self::assertSame(5, $sagas[0]->definitionVersion);
        self::assertSame(4, $sagas[0]->retryTotal);
        self::assertTrue($sagas[0]->typePaused);
        self::assertSame('1700000030', $sagas[0]->startedAt);
        self::assertSame('1700000031', $sagas[0]->updatedAt);
        self::assertSame('1700000032', $sagas[0]->waivedAt);
        self::assertSame('1700000033', $sagas[0]->parentCorrelationId);
        self::assertSame('1700000034', $sagas[0]->pausedAt);
        self::assertNull($sagas[1]->startedAt);
        self::assertNull($sagas[1]->updatedAt);
        self::assertNull($sagas[1]->waivedAt);
        self::assertNull($sagas[1]->parentCorrelationId);
        self::assertNull($sagas[1]->pausedAt);
        self::assertFalse($sagas[1]->typePaused);
    }

    #[Test]
    public function the_rollback_log_decodes_its_records_and_refuses_anything_that_is_not_one(): void
    {
        $log = json_encode([['step' => 'charge', 'status' => 'compensated', 'confirmed' => true]], JSON_THROW_ON_ERROR);

        $decoded = $this->gateway([[[...$this->instanceRow(), 'compensations' => $log]], [], [], []])
            ->inspect('corr-1', null)[0]->compensations;
        self::assertCount(1, $decoded);
        self::assertSame('charge', $decoded[0]->step);

        // a bag that is absent, empty, or holds a scalar is a forensic read finding nothing, never a
        // throw: the operator asked what happened, and "nothing recorded" is an answer
        foreach ([null, '', '"a string"', '17'] as $notALog) {
            $row = [...$this->instanceRow(), 'compensations' => $notALog];
            self::assertSame([], $this->gateway([[$row], [], [], []])->inspect('corr-1', null)[0]->compensations);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function the_retry_ledger_decodes_both_persisted_shapes(): void
    {
        // the bag changed shape from a bare count to a visit ledger, and a row written before that
        // still holds the old one: it reads as a ledger whose window is unknown rather than as zero
        // every field arrives as whatever the bag happens to hold, since the bag is JSON a past build
        // wrote: the counter as a string, the window as something that is not one, and an entry that
        // carries no counter at all, which is a visit opened and not yet retried
        $bag = json_encode([
            'charge' => ['n' => 2, 'since' => '2026-08-23 10:00:00+00'],
            'settle' => '3',
            'audit' => ['n' => '5', 'since' => 1_700_000_040],
            'notify' => ['since' => '2026-08-23 11:00:00+00'],
        ], JSON_THROW_ON_ERROR);

        $retries = $this->gateway([[[...$this->instanceRow(), 'retries' => $bag]], [], [], []])
            ->inspect('corr-1', null)[0]->retries;

        self::assertSame(['n' => 2, 'since' => '2026-08-23 10:00:00+00'], $retries['charge']);
        self::assertSame(['n' => 3, 'since' => null], $retries['settle']);
        self::assertSame(['n' => 5, 'since' => '1700000040'], $retries['audit']);
        self::assertSame(['n' => 0, 'since' => '2026-08-23 11:00:00+00'], $retries['notify']);

        foreach ([null, '', '"a string"', '17'] as $notALedger) {
            $row = [...$this->instanceRow(), 'retries' => $notALedger];
            self::assertSame([], $this->gateway([[$row], [], [], []])->inspect('corr-1', null)[0]->retries);
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function the_exposed_vars_are_an_omission_and_never_a_masking(): void
    {
        // an undeclared key does not EXIST for the reader, in declaration order; and with nothing
        // declared the bag is not even decoded, so a forensic read never leaks a state nobody opted
        // to show
        $vars = json_encode(['tier' => 'gold', 'pan' => '4111', 'score' => 7], JSON_THROW_ON_ERROR);
        $exposing = new WorkflowRegistry([
            new WorkflowDefinition('onboarding', ['done' => new FinalState('done')], 'done', exposedStateKeys: ['score', 'tier', 'absent']),
        ]);

        $row = [...$this->instanceRow(), 'vars' => $vars];
        $exposed = $this->gateway([[$row], [], [], []], $exposing)->inspect('corr-1', null)[0]->exposed;

        self::assertSame(['score' => 7, 'tier' => 'gold'], $exposed);
        self::assertArrayNotHasKey('pan', $exposed);

        // nothing declared, and a type the registry never heard of: both read as nothing exposed
        self::assertSame([], $this->gateway([[$row], [], [], []])->inspect('corr-1', null)[0]->exposed);
        $foreign = [...$row, 'workflow_type' => 'never_registered'];
        self::assertSame([], $this->gateway([[$foreign], [], [], []], $exposing)->inspect('corr-1', null)[0]->exposed);

        // a declaration standing over a bag that is absent or empty exposes nothing rather than throwing
        foreach ([null, ''] as $noBag) {
            $bare = [...$row, 'vars' => $noBag];
            self::assertSame([], $this->gateway([[$bare], [], [], []], $exposing)->inspect('corr-1', null)[0]->exposed);
        }
    }

    /**
     * One recorded read, by call order. An accessor rather than the property itself: a test that
     * clears the log mid-scenario would otherwise leave the analyser certain it is still empty,
     * since the refill happens inside the stub's closure where it cannot follow.
     *
     * @return array{sql: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    private function read(int $nth): array
    {
        self::assertArrayHasKey($nth, $this->reads);

        return $this->reads[$nth];
    }

    private function forgetReads(): void
    {
        $this->reads = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summaryRow(): array
    {
        return [
            'workflow_type' => 'onboarding', 'correlation_id' => 'c-1', 'state_key' => 'await_kyc',
            'status' => 'running', 'version' => 1, 'generation' => 1, 'definition_version' => 1,
            'retry_total' => 0, 'started_at' => '2026-08-23 10:00:00+00',
            'updated_at' => '2026-08-23 10:05:00+00', 'waived_at' => null,
            'paused_at' => null, 'parent_correlation_id' => null, 'type_paused' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function instanceRow(): array
    {
        return [
            'workflow_type' => 'onboarding', 'state_key' => 'await_kyc', 'status' => 'running',
            'vars' => '{}', 'retries' => '{}', 'compensations' => '[]', 'version' => 7,
            'started_at' => '2026-08-23 10:00:00+00', 'updated_at' => '2026-08-23 10:05:00+00',
            'generation' => 3, 'definition_version' => 5, 'state_version' => 2, 'retry_total' => 4,
            'retimes' => 6, 'waived_at' => '2026-08-23 10:06:00+00',
            'paused_at' => '2026-08-23 10:07:00+00', 'paused_reason' => 'operator on call',
            'parent_workflow_type' => 'identity', 'parent_correlation_id' => 'root-1',
            'root_correlation_id' => 'root-0', 'type_paused' => true,
        ];
    }

    /**
     * @param  list<list<array<string, mixed>>>  $results  one result set per `fetchAllAssociative`, in call order
     */
    private function gateway(array $results, ?WorkflowRegistry $registry = null): SagaInspectionGateway
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('transactional')->willReturnCallback(
            static fn (callable $work): mixed => $work($connection),
        );

        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql): int {
                $this->statements[] = $sql;

                return 0;
            },
        );

        $queue = $results;
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params = [], array $types = []) use (&$queue): array {
                $this->reads[] = ['sql' => $sql, 'params' => $params, 'types' => $types];

                return array_shift($queue) ?? [];
            },
        );

        return new SagaInspectionGateway($connection, $registry ?? new WorkflowRegistry([
            new WorkflowDefinition('onboarding', ['done' => new FinalState('done')], 'done'),
        ]));
    }
}
