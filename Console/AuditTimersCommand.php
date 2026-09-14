<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
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

        // the instance's pinned definition version rides along: a tempo that changed between two
        // versions is judged for the version the instance was born under, never the latest
        /** @var list<array{id: int|string, workflow_type: string, correlation_id: string, state_key: string, kind: string, ahead: int|string, definition_version: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            /* language=PostgreSQL */
            'SELECT t.id, t.workflow_type, t.correlation_id, t.state_key, t.kind, EXTRACT(EPOCH FROM (t.fire_at - clock_timestamp()))::int AS ahead, i.definition_version
             FROM workflow_timers t
             LEFT JOIN workflow_instances i ON i.workflow_type = t.workflow_type AND i.correlation_id = t.correlation_id
             WHERE t.parked_at IS NULL'.($type === null ? '' : ' AND t.workflow_type = :type').' ORDER BY t.workflow_type, t.fire_at',
            $type === null ? [] : ['type' => $type],
        );

        $beyond = [];
        $notJudged = 0;
        $definitions = [];
        foreach ($rows as $row) {
            $version = $row['definition_version'] === null ? null : (int) $row['definition_version'];
            $definitions[$row['workflow_type'].'@'.($version ?? 'latest')] ??= $this->definition($row['workflow_type'], $version);
            $tempo = $this->tempo($definitions[$row['workflow_type'].'@'.($version ?? 'latest')], $row['state_key'], $row['kind']);
            if ($tempo === null) {
                $notJudged++;

                continue;
            }
            if ((int) $row['ahead'] > $tempo) {
                $beyond[] = ['id' => (int) $row['id'], 'workflow_type' => $row['workflow_type'], 'correlation_id' => $row['correlation_id'], 'state_key' => $row['state_key'], 'kind' => $row['kind'], 'ahead' => (int) $row['ahead'], 'tempo' => $tempo];
            }
        }

        $rearmed = 0;
        if ($input->getOption('rearm') === true && $beyond !== []) {
            $rearmed = (int) $this->connection->executeStatement(
                /* language=PostgreSQL */
                'UPDATE workflow_timers SET fire_at = clock_timestamp(), claimed_at = NULL WHERE id IN (:ids) AND parked_at IS NULL',
                ['ids' => array_column($beyond, 'id')],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(['beyond' => $beyond, 'not_judged' => $notJudged, 'rearmed' => $rearmed], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            if ($beyond !== []) {
                $io->table(['Workflow', 'Correlation', 'State', 'Kind', 'Ahead (s)', 'Tempo (s)'], array_map(static fn (array $t): array => [$t['workflow_type'], $t['correlation_id'], $t['state_key'], $t['kind'], (string) $t['ahead'], (string) $t['tempo']], $beyond));
            }
            $line = sprintf('%d timer(s) read, %d beyond their tempo, %d not judged (business timeouts, kicks and schedules carry no tempo the audit can read)%s.', count($rows), count($beyond), $notJudged, $rearmed > 0 ? sprintf(', re-armed %d to now', $rearmed) : '');
            $beyond === [] || $rearmed > 0 ? $io->success($line) : $io->error($line.' A timer beyond its tempo was armed by a clock ahead of the database\'s: fix the host\'s time, stop the timers loops, then --rearm.');
        }

        return $beyond === [] || $rearmed > 0 ? Command::SUCCESS : Command::FAILURE;
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
