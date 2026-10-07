<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\FailedWorkflowCommandBatch;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\RedriveBatchScope;
use Storm\Saga\Outbox\RedriveCandidate;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The batch redrive over `workflow_outbox` and `workflow_instances`, sharing the unit redrive's guarded
 * flip, its `PgAdvisoryFence` and its connection.
 *
 * The selection is one `ORDER BY id LIMIT` statement: it returns at most the limit, but a sparse
 * window may scan many rows to find them, since no index covers the type, the status and the creation
 * instant together. Each redrive names its row by primary key AND by the candidate's workflow type and
 * correlation, in the flip and in the diagnosis alike, so the row it mutates always belongs to the
 * saga whose fence it holds.
 */
final readonly class DbalFailedWorkflowCommandBatch implements FailedWorkflowCommandBatch
{
    /** The instant format bound to `timestamptz(6)`, microseconds and offset kept. */
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    /** The row a batch redrive names: its primary key, pinned to the fenced saga. */
    private const string ROW = 'o.id = :id AND o.workflow_type = :type AND o.correlation_id = :corr';

    public function __construct(
        private Connection $connection,
    ) {}

    public function candidates(RedriveBatchScope $scope): array
    {
        $params = [
            'type' => $scope->workflowType,
            'before' => $scope->createdBefore->format(self::INSTANT),
            'after' => $scope->afterId,
            'limit' => $scope->limit,
        ];
        $since = '';

        if ($scope->createdSince !== null) {
            $since = 'AND o.created_at >= CAST(:since AS timestamptz)';
            $params['since'] = $scope->createdSince->format(self::INSTANT);
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    /* language=PostgreSQL */
                    "SELECT o.id, o.workflow_type, o.correlation_id
                     FROM workflow_outbox o
                     JOIN workflow_instances i
                       ON i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                     WHERE o.workflow_type = :type AND o.status = '%s' AND o.evidence = '%s'
                       AND o.created_at < CAST(:before AS timestamptz) %s
                       AND o.id > :after
                       AND i.status = '%s' AND i.generation = o.generation
                     ORDER BY o.id
                     LIMIT :limit",
                    OutboxStatus::Failed->value,
                    EffectEvidence::Uncommitted->value,
                    $since,
                    WorkflowStatus::Running->value,
                ),
                $params,
                ['after' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return array_map(
            static fn (array $row): RedriveCandidate => new RedriveCandidate((int) $row['id'], (string) $row['workflow_type'], (string) $row['correlation_id']),
            $rows,
        );
    }

    public function redrive(RedriveCandidate $candidate): RedriveOutcome
    {
        $mutation = new DbalRedriveMutation($this->connection);
        $params = ['id' => $candidate->id, 'type' => $candidate->workflowType, 'corr' => $candidate->correlationId];
        $types = ['id' => ParameterType::INTEGER];

        return $mutation->underFence(
            $candidate->saga(),
            static fn (): RedriveOutcome => $mutation->flip(self::ROW, $params, $types, false)
                ? RedriveOutcome::Redriven
                : $mutation->diagnose(self::ROW, $params, $types),
        );
    }
}
