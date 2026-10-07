<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox\Dbal;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Storm\Saga\Engine\EffectEvidence;
use Storm\Saga\Exception\FenceIsolationRefused;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Locking\Dbal\PgAdvisoryFence;
use Storm\Saga\Outbox\OutboxStatus;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The guarded flip of one dead-lettered row back to `pending`, shared by the unit and the batch redrive.
 *
 * Each caller names its row through a predicate over the `o` alias of `workflow_outbox`; the guards,
 * the reset columns and the diagnosis order belong to this class alone, so both verbs refuse and
 * reset identically. The flip runs under the saga's `PgAdvisoryFence` on the same connection, so the
 * lock, the guarded update and its diagnosis share one transaction.
 *
 * @internal
 */
final readonly class DbalRedriveMutation
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Run `$flip` under the step fence of `$saga`, standing down as `Raced` when a step holds it.
     *
     * Inside a caller's ambient transaction the fence nests as a savepoint and stays held until the
     * outer commit.
     *
     * @param  Closure(): RedriveOutcome  $flip
     *
     * @throws FenceIsolationRefused when the transaction does not run under `READ COMMITTED`
     * @throws SagaStorageFailure when the storage fails
     */
    public function underFence(WorkflowId $saga, Closure $flip): RedriveOutcome
    {
        $outcome = RedriveOutcome::Raced;

        try {
            $acquired = new PgAdvisoryFence($this->connection)->tryWithin(
                $saga,
                static function () use ($flip, &$outcome): void {
                    $outcome = $flip();
                },
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return $acquired ? $outcome : RedriveOutcome::Raced;
    }

    /**
     * Flip the row named by `$row` back to `pending` when every guard still holds.
     *
     * The guards sit in the predicate of the UPDATE, never read before it: the row is `failed`, its
     * evidence is `uncommitted` unless `$force`, the saga runs and the row belongs to its current
     * generation. The reset clears the attempt budget, the last error and the processed stamp, and
     * sets the evidence back to `unknown`, since a row back in flight has proven nothing yet.
     *
     * @param  string  $row  predicate over `o` naming exactly the row to flip
     * @param  array<string, mixed>  $params
     * @param  array<string, ParameterType>  $types
     * @return bool whether the row flipped
     *
     * @throws SagaStorageFailure when the storage fails
     */
    public function flip(string $row, array $params, array $types, bool $force): bool
    {
        try {
            $redriven = $this->connection->executeStatement(
                sprintf(
                    /* language=PostgreSQL */
                    "UPDATE workflow_outbox o
                     SET status = '%s', attempts = 0, last_error = NULL, processed_at = NULL, evidence = '%s'
                     FROM workflow_instances i
                     WHERE %s AND o.status = '%s'
                       AND (o.evidence = '%s' OR :force)
                       AND i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                       AND i.status = '%s' AND i.generation = o.generation",
                    OutboxStatus::Pending->value,
                    EffectEvidence::Unknown->value,
                    $row,
                    OutboxStatus::Failed->value,
                    EffectEvidence::Uncommitted->value,
                    WorkflowStatus::Running->value,
                ),
                $params + ['force' => $force],
                $types + ['force' => ParameterType::BOOLEAN],
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        return $redriven > 0;
    }

    /**
     * Which guard rejected the row named by `$row`, asked ONLY after the flip changed nothing.
     *
     * The decision was made atomically by the flip; this read merely names it, so a diagnosis that
     * raced with a concurrent settle can be slightly stale without ever having decided anything. An
     * operator handed a bare "nothing happened" goes and edits SQL by hand, which is the outcome the
     * redrive exists to prevent.
     *
     * @param  string  $row  predicate over `o` naming exactly the row to diagnose
     * @param  array<string, mixed>  $params
     * @param  array<string, ParameterType>  $types
     *
     * @throws SagaStorageFailure when the storage fails
     */
    public function diagnose(string $row, array $params, array $types = []): RedriveOutcome
    {
        try {
            $found = $this->connection->fetchAssociative(
                sprintf(
                    /* language=PostgreSQL */
                    'SELECT o.status, o.evidence, o.generation, i.status AS saga_status, i.generation AS saga_generation
                     FROM workflow_outbox o
                     LEFT JOIN workflow_instances i
                       ON i.workflow_type = o.workflow_type AND i.correlation_id = o.correlation_id
                     WHERE %s',
                    $row,
                ),
                $params,
                $types,
            );
        } catch (Exception $e) {
            throw SagaStorageFailure::unavailable($e);
        }

        if ($found === false) {
            return RedriveOutcome::NotFound;
        }

        // ordered by what an operator can act on: the row's own state first, then the saga's, then the
        // one refusal a --force can lift, which must be reported LAST so it is never the answer given
        // for a saga that is beyond repair anyway
        if ((string) $found['status'] !== OutboxStatus::Failed->value) {
            return RedriveOutcome::NotDeadLettered;
        }

        if ($found['saga_status'] === null || (string) $found['saga_status'] !== WorkflowStatus::Running->value) {
            return RedriveOutcome::SagaNotRunning;
        }

        if ((int) $found['saga_generation'] !== (int) $found['generation']) {
            return RedriveOutcome::StaleGeneration;
        }

        if ((string) $found['evidence'] !== EffectEvidence::Uncommitted->value) {
            return RedriveOutcome::EffectUnproven;
        }

        // every guard now reads as satisfied, yet the update changed nothing: the row moved between
        // the two statements. Saying NotFound here, of a row just read, would be the diagnosis
        // inventing a reason rather than reporting one.
        return RedriveOutcome::Raced;
    }
}
