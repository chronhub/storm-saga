<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Inspection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Storm\Message\Header;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Saga\Workflow\CompensationRecord;
use Throwable;

/**
 * Read-only forensic introspection of the saga tables: the instances on a correlation, each with its
 * armed timers, the commands it has issued, and its compensation log, THE forensic data of a halted
 * saga. Owns the three-table read schema over `workflow_instances`, `workflow_timers`, and
 * `workflow_outbox` so the console commands stay pure presentation. Direct DBAL: a forensic read
 * outside the saga's co-transactional group, so it does not go through the port's
 * `SagaStorageFailure` wrapping.
 *
 * @see \Storm\Saga\Console\InspectSagaCommand the console consumer
 * @see \Storm\Saga\Console\ListSagasCommand the listing's console consumer
 */
final readonly class SagaInspectionGateway
{
    /** Rows a listing returns when the caller states no preference. */
    public const int DEFAULT_LIMIT = 50;

    /** The ceiling a caller cannot argue past, whatever it asks for. */
    public const int MAX_LIMIT = 500;

    public function __construct(
        private Connection $connection,
        private WorkflowRegistry $registry,
    ) {}

    /**
     * Every saga sharing this correlation, optionally narrowed to one workflow type, each with its
     * timers, outbox commands, and compensation log. Empty when none matches. The read goes straight
     * to the tables precisely so the cross-saga case of several workflow types on one correlation
     * stays visible rather than collapsed like the store's `find()` and `findByCorrelation()`.
     *
     * The 1+2N reads run inside ONE `REPEATABLE READ` transaction, so the snapshot is a single
     * consistent picture where a saga advancing mid-inspection cannot show its new timers against
     * its old state; a read-only isolation level costs nothing on Postgres.
     *
     * @return list<SagaSnapshot>
     *
     * @throws Exception on a DBAL read failure
     * @throws JsonException on a retries- or compensations-bag decode failure
     * @throws Throwable rethrown from the read-only transaction wrapper
     */
    public function inspect(string $correlation, ?string $type): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($correlation, $type): array {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            /** @lang PostgreSQL */
            // the type freeze leaves NO stamp on its instances; without the EXISTS an inspect reads a
            // living saga as executable while its whole type is held
            $sql = 'SELECT i.workflow_type, i.state_key, i.status, i.vars, i.retries, i.compensations, i.version, i.started_at,
                           i.updated_at, i.generation, i.definition_version, i.state_version, i.retry_total, i.retimes,
                           i.waived_at, i.paused_at, i.paused_reason,
                           i.parent_workflow_type, i.parent_correlation_id, i.root_correlation_id,
                           EXISTS (SELECT 1 FROM workflow_pauses p WHERE p.workflow_type = i.workflow_type) AS type_paused
                    FROM workflow_instances i WHERE i.correlation_id = :corr';
            $params = ['corr' => $correlation];

            if ($type !== null) {
                $sql .= ' AND i.workflow_type = :type';
                $params['type'] = $type;
            }

            $instances = $connection->fetchAllAssociative($sql.' ORDER BY i.workflow_type', $params);

            return array_map(
                fn (array $row): SagaSnapshot => new SagaSnapshot(
                    workflowType: (string) $row['workflow_type'],
                    stateKey: (string) $row['state_key'],
                    status: (string) $row['status'],
                    version: (int) $row['version'],
                    startedAt: $row['started_at'] !== null ? (string) $row['started_at'] : null,
                    updatedAt: $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
                    generation: (int) $row['generation'],
                    definitionVersion: (int) $row['definition_version'],
                    retryTotal: (int) $row['retry_total'],
                    waivedAt: $row['waived_at'] !== null ? (string) $row['waived_at'] : null,
                    retries: $this->decodeRetries($row['retries']),
                    compensations: $this->decodeCompensations($row['compensations']),
                    timers: $this->timers((string) $row['workflow_type'], $correlation),
                    outbox: $this->outbox((string) $row['workflow_type'], $correlation),
                    parentWorkflowType: $row['parent_workflow_type'] !== null ? (string) $row['parent_workflow_type'] : null,
                    parentCorrelationId: $row['parent_correlation_id'] !== null ? (string) $row['parent_correlation_id'] : null,
                    rootCorrelationId: $row['root_correlation_id'] !== null ? (string) $row['root_correlation_id'] : null,
                    children: $this->children($correlation),
                    stateVersion: (int) $row['state_version'],
                    exposed: $this->exposedVars((string) $row['workflow_type'], $row['vars']),
                    retimes: (int) $row['retimes'],
                    pausedAt: $row['paused_at'] !== null ? (string) $row['paused_at'] : null,
                    typePaused: (bool) $row['type_paused'],
                    pausedReason: $row['paused_reason'] !== null ? (string) $row['paused_reason'] : null,
                ),
                $instances,
            );
        });
    }

    /**
     * The sagas themselves, filtered, for the operator who has an incident and NOT a correlation id.
     * Every other read on this gateway demands the correlation up front; this is the door that opens
     * without one.
     *
     * ONE query, one row per instance, no satellites: a listing that carried each saga's timers and
     * outbox would turn a filtered scan into an N+1 over an unbounded population. `inspect()` is the
     * next hop once a row looks wrong, and it is the one that pays for consistency.
     *
     * Ordered oldest-touched first, which is the operator's question: what has been sitting there
     * the longest. The `(status, updated_at)` index serves that order only under an equality on
     * `status`, its leading column; the door that opens without one reads the population and top-N
     * sorts it, which is the price of the door rather than a defect of the index. Narrowing by
     * status is therefore what keeps a large park's listing cheap. `$limit` is clamped server-side,
     * and the answer says whether the clamp cut it short: an unannounced cap reads like a complete
     * answer.
     *
     * @param  string|null  $type  narrow to one workflow type
     * @param  WorkflowStatus|null  $status  narrow to one lifecycle status
     * @param  int|null  $idleForSeconds  only rows untouched for at least this long, the stuck-saga filter
     * @param  bool  $waivedOnly  only rows whose retry budget was waived
     * @param  int  $limit  requested cap, clamped to [1, self::MAX_LIMIT]
     *
     * @throws Exception on a DBAL read failure
     */
    public function list(
        ?string $type = null,
        ?WorkflowStatus $status = null,
        ?int $idleForSeconds = null,
        bool $waivedOnly = false,
        int $limit = self::DEFAULT_LIMIT,
    ): SagaListing {
        $capped = max(1, min(self::MAX_LIMIT, $limit));

        $where = [];
        $params = [];
        $types = [];

        if ($type !== null && $type !== '') {
            $where[] = 'i.workflow_type = :type';
            $params['type'] = $type;
        }

        if ($status instanceof WorkflowStatus) {
            $where[] = 'i.status = :status';
            $params['status'] = $status->value;
        }

        if ($idleForSeconds !== null && $idleForSeconds > 0) {
            // the cutoff is computed once rather than per row, which is what lets the predicate ride
            // (status, updated_at) whenever a status has already pinned that index's leading column
            $where[] = 'i.updated_at <= clock_timestamp() - make_interval(secs => :idle)';
            $params['idle'] = $idleForSeconds;
            $types['idle'] = ParameterType::INTEGER;
        }

        if ($waivedOnly) {
            $where[] = 'i.waived_at IS NOT NULL';
        }

        // one row over the cap, so truncation is KNOWN rather than guessed, and no count(*) is paid
        $params['n'] = $capped + 1;
        $types['n'] = ParameterType::INTEGER;

        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            // the type freeze leaves NO stamp on its instances, it lives in workflow_pauses alone, so
            // without this the widest freeze of the surface is invisible in every listing
            'SELECT i.workflow_type, i.correlation_id, i.state_key, i.status, i.version, i.generation,
                    i.definition_version, i.retry_total, i.started_at, i.updated_at, i.waived_at,
                    i.paused_at, i.parent_correlation_id,
                    EXISTS (SELECT 1 FROM workflow_pauses p WHERE p.workflow_type = i.workflow_type) AS type_paused
             FROM workflow_instances i'
            .($where === [] ? '' : ' WHERE '.implode(' AND ', $where))
            .' ORDER BY i.updated_at ASC NULLS LAST, i.correlation_id LIMIT :n',
            $params,
            $types,
        );

        $truncated = count($rows) > $capped;

        return new SagaListing(
            sagas: array_map(static fn (array $r): SagaSummarySnapshot => new SagaSummarySnapshot(
                workflowType: (string) $r['workflow_type'],
                correlationId: (string) $r['correlation_id'],
                stateKey: (string) $r['state_key'],
                status: (string) $r['status'],
                version: (int) $r['version'],
                generation: (int) $r['generation'],
                definitionVersion: (int) $r['definition_version'],
                retryTotal: (int) $r['retry_total'],
                startedAt: $r['started_at'] !== null ? (string) $r['started_at'] : null,
                updatedAt: $r['updated_at'] !== null ? (string) $r['updated_at'] : null,
                waivedAt: $r['waived_at'] !== null ? (string) $r['waived_at'] : null,
                parentCorrelationId: $r['parent_correlation_id'] !== null ? (string) $r['parent_correlation_id'] : null,
                pausedAt: $r['paused_at'] !== null ? (string) $r['paused_at'] : null,
                typePaused: (bool) $r['type_paused'],
            ), $truncated ? array_slice($rows, 0, $capped) : $rows),
            truncated: $truncated,
            limit: $capped,
        );
    }

    /**
     * The living children of settled or vanished parents, the zombie sweep, READ only: the
     * cascade is the authority that reaches a child, this view is the backstop that makes a missed
     * cascade visible. A null parent status is the harder orphan, a parent row already gone.
     *
     * The cap is read one row over, so a full page is distinguishable from a finished population: every
     * row here is an instruction to cancel something, and an operator who works the list to its end
     * must not believe the sweep is clean when the cap merely ran out.
     *
     * @throws Exception on a DBAL read failure
     */
    public function zombieChildren(int $limit = self::DEFAULT_LIMIT): ZombieListing
    {
        $capped = max(1, min(self::MAX_LIMIT, $limit));

        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            "SELECT c.workflow_type, c.correlation_id, c.parent_correlation_id, p.status AS parent_status, c.started_at
             FROM workflow_instances c
             LEFT JOIN workflow_instances p ON p.correlation_id = c.parent_correlation_id
             WHERE c.parent_correlation_id IS NOT NULL AND c.status = 'running'
               AND (p.correlation_id IS NULL OR p.status <> 'running')
             ORDER BY c.started_at LIMIT :n",
            ['n' => $capped + 1],
            ['n' => ParameterType::INTEGER],
        );

        $truncated = count($rows) > $capped;

        return new ZombieListing(children: array_map(static fn (array $r): ZombieChildSnapshot => new ZombieChildSnapshot(
            workflowType: (string) $r['workflow_type'],
            correlationId: (string) $r['correlation_id'],
            parentCorrelationId: (string) $r['parent_correlation_id'],
            parentStatus: $r['parent_status'] !== null ? (string) $r['parent_status'] : null,
            startedAt: $r['started_at'] !== null ? (string) $r['started_at'] : null,
        ), $truncated ? array_slice($rows, 0, $capped) : $rows),
            truncated: $truncated,
            limit: $capped,
        );
    }

    /**
     * @return list<ChildSnapshot>
     *
     * @throws Exception
     */
    private function children(string $parentCorrelation): array
    {
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            'SELECT workflow_type, correlation_id, status FROM workflow_instances
             WHERE parent_correlation_id = :corr ORDER BY correlation_id',
            ['corr' => $parentCorrelation],
        );

        return array_map(static fn (array $r): ChildSnapshot => new ChildSnapshot(
            workflowType: (string) $r['workflow_type'],
            correlationId: (string) $r['correlation_id'],
            status: (string) $r['status'],
        ), $rows);
    }

    /**
     * @return list<TimerSnapshot>
     *
     * @throws Exception
     */
    private function timers(string $type, string $correlation): array
    {
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            'SELECT id, kind, state_key, fire_at, claimed_at, attempts, parked_at, last_error FROM workflow_timers
             WHERE workflow_type = :type AND correlation_id = :corr ORDER BY fire_at',
            ['type' => $type, 'corr' => $correlation],
        );

        return array_map(static fn (array $r): TimerSnapshot => new TimerSnapshot(
            id: (int) $r['id'],
            kind: (string) $r['kind'],
            stateKey: (string) $r['state_key'],
            fireAt: (string) $r['fire_at'],
            claimedAt: $r['claimed_at'] !== null ? (string) $r['claimed_at'] : null,
            attempts: (int) $r['attempts'],
            parkedAt: $r['parked_at'] !== null ? (string) $r['parked_at'] : null,
            lastError: $r['last_error'] !== null ? (string) $r['last_error'] : null,
        ), $rows);
    }

    /**
     * @return list<OutboxSnapshot>
     *
     * @throws Exception
     */
    private function outbox(string $type, string $correlation): array
    {
        $rows = $this->connection->fetchAllAssociative(
            /** @lang PostgreSQL */
            sprintf(
                "SELECT status, bus, attempts, header->>'%s' AS command, header->>'%s' AS message_id,
                        created_at, last_error, issued_from_state, issued_at_version, generation, evidence, effect_group
                 FROM workflow_outbox WHERE workflow_type = :type AND correlation_id = :corr ORDER BY id",
                Header::MessageType->value,
                Header::MessageId->value,
            ),
            ['type' => $type, 'corr' => $correlation],
        );

        return array_map(static fn (array $r): OutboxSnapshot => new OutboxSnapshot(
            status: (string) $r['status'],
            command: $r['command'] !== null ? (string) $r['command'] : null,
            bus: (string) $r['bus'],
            attempts: (int) $r['attempts'],
            createdAt: (string) $r['created_at'],
            lastError: $r['last_error'] !== null ? (string) $r['last_error'] : null,
            issuedFromState: (string) $r['issued_from_state'],
            issuedAtVersion: (int) $r['issued_at_version'],
            generation: (int) $r['generation'],
            messageId: $r['message_id'] !== null ? (string) $r['message_id'] : null,
            evidence: EffectEvidence::tryFrom((string) $r['evidence']) ?? EffectEvidence::Unknown,
            effectGroup: $r['effect_group'] !== null ? (string) $r['effect_group'] : null,
        ), $rows);
    }

    /**
     * The rollback log, THE forensic data of a halted saga: which completed steps were undone, which
     * were skipped and why, in completion order.
     *
     * @return list<CompensationRecord>
     *
     * @throws JsonException
     */
    private function decodeCompensations(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var list<array{step: string, status: string, confirmed?: bool, degraded?: bool, reason?: string|null, at?: string|null}> $decoded the toArray() round-trip shape */
        return array_map(CompensationRecord::fromArray(...), $decoded);
    }

    /**
     * The exposed subset of `vars`, filtered by the workflow's compiled `#[ExposesState]` allowlist
     * in declaration order: OMISSION, never masking. An undeclared key does not exist for the
     * reader, and with no declaration the bag is not even decoded. The registry is the per-name
     * authority, co-registered versions agreeing by law, and a purged type exposes nothing rather
     * than failing a forensic read.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException on a stored-vars decode failure, storage corruption surfacing loudly
     */
    private function exposedVars(string $workflowType, mixed $varsJson): array
    {
        if (! $this->registry->has($workflowType)) {
            return [];
        }

        $keys = $this->registry->get($workflowType)->exposedStateKeys;
        if ($keys === [] || ! is_string($varsJson) || $varsJson === '') {
            return [];
        }

        /** @var array<string, mixed> $vars */
        $vars = json_decode($varsJson, true, flags: JSON_THROW_ON_ERROR);

        $exposed = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $vars)) {
                $exposed[$key] = $vars[$key];
            }
        }

        return $exposed;
    }

    /**
     * Both persisted shapes decode: the visit-ledger form `{state: {n, since}}`, and the legacy bare
     * count a not-yet-rewritten row still holds, read as a ledger with an unknown window.
     *
     * @return array<string, array{n: int, since: string|null}> state key => visit ledger
     *
     * @throws JsonException
     */
    private function decodeRetries(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return [];
        }

        $retries = [];
        foreach ($decoded as $state => $entry) {
            // The cast answers the ANALYSER, never the runtime: a decoded object hands back
            // `int|string` keys, PHP folds a numeric-string key to an int, and writing the string
            // back folds it again, so no assertion can separate the two. Deliberately NOT pinned:
            // the ternary below shares this line, and an ignore here took ten killed mutants with it.
            $retries[(string) $state] = is_array($entry)
                ? ['n' => (int) ($entry['n'] ?? 0), 'since' => isset($entry['since']) ? (string) $entry['since'] : null]
                : ['n' => (int) $entry, 'since' => null];
        }

        return $retries;
    }
}
