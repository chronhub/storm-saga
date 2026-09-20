<?php

declare(strict_types=1);

namespace Storm\Saga\Store\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStepWrites;
use Storm\Serializer\MessageSerializer;

/**
 * {@inheritDoc}
 *
 * One server statement per step. The OCC update is the root branch, and every other branch depends
 * on its returned row through an EXISTS, so a version that moved updates nothing, cancels nothing,
 * arms nothing and issues nothing, the count the statement returns then being zero. The branches key
 * their own rows by the bound id rather than by the root's columns: the instance's correlation is
 * pinned to another collation, and a join on it would lose the timer index. No row is touched twice inside the statement: the effects come
 * folded, a cancel sparing the kinds the same step re-arms, so the delete and the upsert on
 * `workflow_timers` never meet on one row; a data-modifying `WITH` guarantees nothing about the order
 * of its branches, and this shape needs none.
 *
 * Without a row to update, a birth or an escalation, the root branch is the id itself and the
 * same branches hang off it.
 */
final readonly class DbalWorkflowStepWrites implements WorkflowStepWrites
{
    private const string TIMER_UPSERT = 'ON CONFLICT (workflow_type, correlation_id, state_key, kind)
                 DO UPDATE SET fire_at = EXCLUDED.fire_at, claimed_at = NULL, attempts = 0, parked_at = NULL, last_error = NULL';

    public function __construct(
        private Connection $connection,
        private MessageSerializer $serializer,
        private string $bus = 'storm.command.bus',
    ) {}

    public function commitAdvance(WorkflowInstanceRow $row, StepEffects $effects, array $commands): void
    {
        $params = DbalWorkflowInstanceStore::updateParameters($row);
        $types = DbalWorkflowInstanceStore::UPDATE_TYPES;
        $root = DbalWorkflowInstanceStore::UPDATE_SQL.' RETURNING workflow_type, correlation_id';

        $updated = $this->run($root, $effects, $commands, $params, $types);

        if ($updated === 0) {
            throw StaleWorkflowInstance::forStep($row->workflowType, $row->correlationId, $row->version);
        }
    }

    public function applyEffects(WorkflowId $id, StepEffects $effects, array $commands): void
    {
        if ($effects->isEmpty() && $commands === []) {
            return;
        }

        $this->run(
            'SELECT CAST(:type AS text) AS workflow_type, CAST(:corr AS text) AS correlation_id',
            $effects,
            $commands,
            ['type' => $id->workflowType, 'corr' => $id->correlationId],
            [],
        );
    }

    /**
     * @param  list<\Storm\Saga\Store\OutboxEntry>  $commands
     * @param  array<string, mixed>  $params
     * @param  array<string, ParameterType>  $types
     * @return int the rows the root branch produced
     */
    private function run(string $root, StepEffects $effects, array $commands, array $params, array $types): int
    {
        $branches = ['upd AS ('.$root.')'];

        if ($effects->cancels !== []) {
            $keys = [];
            foreach ($effects->cancels as $i => ['stateKey' => $stateKey, 'keepKinds' => $keepKinds]) {
                $params["c{$i}s"] = $stateKey;
                $clause = "t.state_key = :c{$i}s";
                if ($keepKinds !== []) {
                    $names = [];
                    foreach ($keepKinds as $j => $kind) {
                        $params["c{$i}k{$j}"] = $kind;
                        $names[] = ":c{$i}k{$j}";
                    }
                    $clause .= ' AND t.kind NOT IN ('.implode(', ', $names).')';
                }
                $keys[] = '('.$clause.')';
            }
            // keyed by the bound id, never through the CTE's columns: a join on `upd` loses the unique
            // index, the instance's correlation being pinned to another collation, and scans the
            // workflow's whole timer set; the dependency on the root branch is the EXISTS
            $branches[] = 'cancelled AS (
                DELETE FROM workflow_timers t
                WHERE t.workflow_type = :type AND t.correlation_id = :corr
                  AND ('.implode(' OR ', $keys).')
                  AND EXISTS (SELECT 1 FROM upd))';
        }

        if ($effects->arms !== []) {
            $rows = [];
            foreach ($effects->arms as $i => ['stateKey' => $stateKey, 'kind' => $kind, 'fireAt' => $fireAt]) {
                $params["a{$i}s"] = $stateKey;
                $params["a{$i}k"] = $kind->value;
                $params["a{$i}f"] = $fireAt->toString();
                $rows[] = "(CAST(:a{$i}s AS text), CAST(:a{$i}k AS text), CAST(:a{$i}f AS timestamptz))";
            }
            $branches[] = 'armed AS (
                INSERT INTO workflow_timers (workflow_type, correlation_id, state_key, kind, fire_at)
                SELECT CAST(:type AS text), CAST(:corr AS text), v.state_key, v.kind, v.fire_at
                FROM (VALUES '.implode(', ', $rows).') AS v(state_key, kind, fire_at)
                WHERE EXISTS (SELECT 1 FROM upd)
                '.self::TIMER_UPSERT.')';
        }

        if ($commands !== []) {
            $rows = [];
            foreach ($commands as $i => $entry) {
                ['header' => $header, 'content' => $content] = $this->serializer->serialize($entry->message);
                $params["o{$i}b"] = $this->bus;
                $params["o{$i}h"] = self::json($header);
                $params["o{$i}c"] = self::json($content);
                $params["o{$i}s"] = $entry->issuedFromState;
                $params["o{$i}v"] = $entry->issuedAtVersion;
                $params["o{$i}g"] = $entry->generation;
                $params["o{$i}e"] = $entry->effectGroup;
                $types["o{$i}v"] = ParameterType::INTEGER;
                $types["o{$i}g"] = ParameterType::INTEGER;
                $rows[] = "(CAST(:o{$i}b AS text), CAST(:o{$i}h AS jsonb), CAST(:o{$i}c AS jsonb), CAST(:o{$i}s AS text), CAST(:o{$i}v AS integer), CAST(:o{$i}g AS integer), CAST(:o{$i}e AS text))";
            }
            $branches[] = 'issued AS (
                INSERT INTO workflow_outbox (workflow_type, correlation_id, bus, header, content, issued_from_state, issued_at_version, generation, effect_group)
                SELECT CAST(:type AS text), CAST(:corr AS text), v.bus, v.header, v.content, v.from_state, v.at_version, v.generation, v.effect_group
                FROM (VALUES '.implode(', ', $rows).') AS v(bus, header, content, from_state, at_version, generation, effect_group)
                WHERE EXISTS (SELECT 1 FROM upd))';
        }

        try {
            return (int) $this->connection->fetchOne(
                'WITH '.implode(",\n", $branches)."\nSELECT count(*) FROM upd",
                $params,
                $types,
            );
        } catch (Exception|JsonException|ClockExceptionContract $e) {
            throw SagaStorageFailure::unavailable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
