<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Override;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Workflow\WaitState;
use Storm\Saga\Workflow\WorkflowDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Reads the live saga timers against the tempo their workflow declares and names the ones armed
 * further out than it: the trace a host whose clock jumped forward leaves, every heartbeat it
 * fired early re-armed by the jump beyond its tempo, silence that reads as liveness once the
 * clock is fixed. The framework stores no horizon beside a timer, so the horizon comes from the
 * registry: a state's timeout in seconds for a `timeout` timer at that state, the global timeout
 * for a `global` one; a business-day timeout, a kick and a schedule are not judged and are counted
 * as such. A timer at most its tempo ahead of the database clock is in order whenever it was
 * armed; one further out was armed by a clock ahead of the database's.
 *
 * `--rearm` brings the named timers to now and clears their claim, so the next timers run fires
 * them, the ordinary re-arm following; a timer parked meanwhile is left parked, nothing else is
 * touched. The timers loops are stopped first: a claim cleared under a worker still running the
 * step lets a second worker take it. Exit FAILURE while a timer is beyond its tempo and not
 * re-armed, SUCCESS otherwise.
 *
 * Visits timers in increasing id order up to the initial maximum id, without a transaction
 * snapshot. Each page is rendered and optionally re-armed before reading the next one. Memory
 * depends on page contents and workflow definitions, rather than the full timer population.
 * JSON is streamed as one document; an interrupted run can leave an incomplete document and
 * already re-armed pages remain committed.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:timers:audit
 * ```
 *
 * ```bash
 * bin/console storm:saga:timers:audit --type=transfer --json
 * ```
 *
 * ```bash
 * bin/console storm:saga:timers:audit --rearm
 * ```
 */
#[AsCommand(name: 'storm:saga:timers:audit', description: 'Name the live saga timers armed further out than the tempo their workflow declares, the trace of a clock that jumped, and re-arm them to now on demand.')]
final class AuditTimersCommand extends Command
{
    private const int PAGE_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Audit one workflow type only');
        $this->addOption('rearm', null, InputOption::VALUE_NONE, 'Bring the timers beyond their tempo to now and clear their claim');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable verdict');
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception on a DBAL failure reading or re-arming the timers
     * @throws JsonException when the machine document cannot be encoded
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');
        $type = is_string($type) && $type !== '' ? $type : null;

        $ceiling = $this->connection->fetchOne('SELECT max(id) FROM workflow_timers');
        $cursor = null;
        $read = 0;
        $beyondCount = 0;
        $notJudged = 0;
        $rearmed = 0;
        $json = $input->getOption('json') === true;
        if ($json) {
            $output->write('{"beyond":[');
        }

        while ($ceiling !== null && $ceiling !== false) {
            // the instance's pinned version determines its tempo, never the latest version
            /** @var list<array{id: int|string, workflow_type: string, correlation_id: string, state_key: string, kind: string, ahead: int|string, definition_version: int|string|null}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                /* language=PostgreSQL */
                'SELECT t.id, t.workflow_type, t.correlation_id, t.state_key, t.kind, EXTRACT(EPOCH FROM (t.fire_at - clock_timestamp()))::int AS ahead, i.definition_version
                 FROM workflow_timers t
                 LEFT JOIN workflow_instances i ON i.workflow_type = t.workflow_type AND i.correlation_id = t.correlation_id
                 WHERE t.parked_at IS NULL AND t.id <= :ceiling'
                    .($cursor === null ? '' : ' AND t.id > :cursor')
                    .($type === null ? '' : ' AND t.workflow_type = :type')
                    .' ORDER BY t.id LIMIT :limit',
                ['ceiling' => $ceiling, 'limit' => self::PAGE_SIZE]
                    + ($cursor === null ? [] : ['cursor' => $cursor])
                    + ($type === null ? [] : ['type' => $type]),
                ['limit' => ParameterType::INTEGER],
            );
            if ($rows === []) {
                break;
            }
            $cursor = $rows[array_key_last($rows)]['id'];
            $read += count($rows);
            $beyond = [];
            $definitions = [];
            foreach ($rows as $row) {
                $version = $row['definition_version'] === null ? null : (int) $row['definition_version'];
                $key = $row['workflow_type'].'@'.($version ?? 'latest');
                if (! array_key_exists($key, $definitions)) {
                    $definitions[$key] = $this->definition($row['workflow_type'], $version);
                }
                $tempo = $this->tempo($definitions[$key], $row['state_key'], $row['kind']);
                if ($tempo === null) {
                    $notJudged++;

                    continue;
                }
                if ((int) $row['ahead'] > $tempo) {
                    $beyond[] = ['id' => (int) $row['id'], 'workflow_type' => $row['workflow_type'], 'correlation_id' => $row['correlation_id'], 'state_key' => $row['state_key'], 'kind' => $row['kind'], 'ahead' => (int) $row['ahead'], 'tempo' => $tempo];
                }
            }

            if ($input->getOption('rearm') === true && $beyond !== []) {
                $rearmed += (int) $this->connection->executeStatement(
                    /* language=PostgreSQL */
                    'UPDATE workflow_timers SET fire_at = clock_timestamp(), claimed_at = NULL WHERE id IN (:ids) AND parked_at IS NULL',
                    ['ids' => array_column($beyond, 'id')],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            if ($json) {
                foreach ($beyond as $timer) {
                    $output->write(($beyondCount > 0 ? ',' : '').json_encode($timer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    $beyondCount++;
                }
            } else {
                $beyondCount += count($beyond);
                if ($beyond !== []) {
                    $io->table(['Workflow', 'Correlation', 'State', 'Kind', 'Ahead (s)', 'Tempo (s)'], array_map(static fn (array $t): array => [$t['workflow_type'], $t['correlation_id'], $t['state_key'], $t['kind'], (string) $t['ahead'], (string) $t['tempo']], $beyond));
                }
            }
        }

        if ($json) {
            $output->writeln(sprintf('],"not_judged":%d,"rearmed":%d}', $notJudged, $rearmed));
        } else {
            $line = sprintf('%d timer(s) read, %d beyond their tempo, %d not judged (business timeouts, kicks and schedules carry no tempo the audit can read)%s.', $read, $beyondCount, $notJudged, $rearmed > 0 ? sprintf(', re-armed %d to now', $rearmed) : '');
            $beyondCount === 0 || $rearmed > 0 ? $io->success($line) : $io->error($line.' A timer beyond its tempo was armed by a clock ahead of the database\'s: fix the host\'s time, stop the timers loops, then --rearm.');
        }

        return $beyondCount === 0 || $rearmed > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The definition the instance was born under, the latest when no instance carries the timer, or
     * null when the registry knows none: its timers are then not judged rather than misjudged
     * against another workflow's tempo.
     */
    private function definition(string $type, ?int $version): ?WorkflowDefinition
    {
        try {
            return $this->registry->get($type, $version);
        } catch (WorkflowNotFound|WorkflowVersionNotFound) {
            return null;
        } catch (Throwable) {
            // a definition that no longer assembles is storm:saga:validate's to name, not this audit's
            return null;
        }
    }

    /**
     * The seconds the workflow declares for a timer of this kind at this state, or null when it
     * declares none in seconds.
     */
    private function tempo(?WorkflowDefinition $definition, string $stateKey, string $kind): ?int
    {
        if ($definition === null) {
            return null;
        }
        if ($kind === 'global') {
            return $definition->globalTimeout;
        }
        $state = $definition->states()[$stateKey] ?? null;
        if ($kind !== 'timeout' || $state === null) {
            return null;
        }
        if (! $state instanceof WaitState || $state->timeout === null || $state->timeout->isBusiness()) {
            return null;
        }

        return max(1, $state->timeout->seconds);
    }
}
