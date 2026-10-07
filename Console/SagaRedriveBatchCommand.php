<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use DateTimeImmutable;
use Exception;
use Override;
use Storm\Saga\Exception\FenceIsolationRefused;
use Storm\Saga\Exception\InvalidRedriveBatch;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\FailedWorkflowCommandBatch;
use Storm\Saga\Outbox\RedriveBatchScope;
use Storm\Saga\Outbox\RedriveCandidate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Preview or redrive one bounded page of dead-lettered saga commands proven uncommitted.
 *
 * The batch twin of `storm:saga:redrive` for an incident that dead-lettered many commands of one
 * workflow type. It carries no `--force`: only a command whose evidence proves the effect uncommitted
 * is selected, and an unproven one stays a unit decision. Preview is the default and changes nothing;
 * `--apply` redrives each candidate by primary key under its saga's fence, every guard re-checked.
 *
 * Every input is validated before storage is touched:
 *
 * - `--limit` is an integer from 1 to 1000, a ceiling on candidates, not on rows scanned
 *
 * - `--created-before` is exclusive and `--created-since` inclusive, both RFC 3339 with an explicit
 *   offset; the window dates the command's creation, never its failure
 *
 * - `--after-id` resumes after the cursor of an earlier pass, with the same type and window
 *
 * The output is JSONL: one line per candidate with its id, workflow type, correlation and outcome,
 * then one summary line with the counts and the cursor, the id of the last candidate examined. A
 * refusal or a busy fence is reported and the pass continues. A storage failure stops the pass with
 * a nonzero exit, reports that row as `unknown` and leaves the cursor before it; the outcomes already
 * printed stay committed. The lines are flushed as they happen, which is progress, not a
 * transactional audit: a lost connection can leave the last outcome unknown.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:redrive-batch payment --limit=100 --created-before=2026-01-04T00:00:00Z
 * ```
 *
 * ```bash
 * bin/console storm:saga:redrive-batch payment --limit=100 --created-since=2026-01-03T00:00:00Z --created-before=2026-01-04T00:00:00Z --apply
 * ```
 *
 * ```bash
 * bin/console storm:saga:redrive-batch payment --limit=100 --created-before=2026-01-04T00:00:00Z --after-id=4812 --apply
 * ```
 */
#[AsCommand(name: 'storm:saga:redrive-batch', description: 'Preview or redrive one bounded page of proven uncommitted saga commands.')]
final class SagaRedriveBatchCommand extends Command
{
    /** RFC 3339 with an explicit offset, fraction up to the microsecond the column stores. */
    private const string INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/';

    public function __construct(
        private readonly FailedWorkflowCommandBatch $commands,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('workflowType', InputArgument::REQUIRED, 'The workflow type whose dead-lettered commands are examined');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The maximum number of candidates examined, from 1 to 1000');
        $this->addOption('created-before', null, InputOption::VALUE_REQUIRED, 'Exclusive upper bound on the command creation instant, RFC 3339 with an offset');
        $this->addOption('created-since', null, InputOption::VALUE_REQUIRED, 'Inclusive lower bound on the command creation instant, RFC 3339 with an offset');
        $this->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Resume after this outbox id, the cursor of an earlier pass');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Redrive the candidates; without it the pass is a preview that changes nothing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws FenceIsolationRefused when the connection runs another isolation level than `READ COMMITTED`
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scope = self::scope($input);

        if (is_string($scope)) {
            self::emit($output, ['error' => 'invalid_input', 'message' => $scope]);

            return Command::INVALID;
        }

        $apply = $input->getOption('apply') === true;
        $summary = ['candidates' => 0, 'redriven' => 0, 'refused' => 0, 'cursor' => null, 'mode' => $apply ? 'apply' : 'preview', 'complete' => false];

        try {
            $candidates = $this->commands->candidates($scope);
        } catch (SagaStorageFailure) {
            self::emit($output, $summary + ['error' => 'storage_failure']);

            return Command::FAILURE;
        }

        $summary['candidates'] = count($candidates);

        foreach ($candidates as $candidate) {
            if (! $apply) {
                self::emit($output, self::line($candidate, 'preview'));
                $summary['cursor'] = $candidate->id;

                continue;
            }

            try {
                $outcome = $this->commands->redrive($candidate);
            } catch (SagaStorageFailure) {
                // the flip may or may not have committed: reported as unknown, and the cursor stays
                // before this row so a resumed pass examines it again
                self::emit($output, self::line($candidate, 'unknown'));
                self::emit($output, $summary + ['error' => 'storage_failure']);

                return Command::FAILURE;
            }

            self::emit($output, self::line($candidate, $outcome->value));
            if ($outcome->applied()) {
                $summary['redriven']++;
            } else {
                $summary['refused']++;
            }
            $summary['cursor'] = $candidate->id;
        }

        $summary['complete'] = true;
        self::emit($output, $summary);

        return Command::SUCCESS;
    }

    /**
     * The scope the input describes, or the operator-facing reason it describes none.
     */
    private static function scope(InputInterface $input): RedriveBatchScope|string
    {
        $limit = $input->getOption('limit');

        if (! is_string($limit) || preg_match('/^[1-9]\d{0,3}$/', $limit) !== 1) {
            return sprintf('--limit is required as an integer from 1 to %d.', RedriveBatchScope::MAX_LIMIT);
        }

        $before = self::instant($input->getOption('created-before'));

        if ($before === null) {
            return '--created-before is required as an RFC 3339 instant with an explicit offset.';
        }

        $since = null;
        $rawSince = $input->getOption('created-since');

        if ($rawSince !== null) {
            $since = self::instant($rawSince);

            if ($since === null) {
                return '--created-since must be an RFC 3339 instant with an explicit offset.';
            }
        }

        $afterId = 0;
        $rawAfterId = $input->getOption('after-id');

        if ($rawAfterId !== null) {
            $afterId = is_string($rawAfterId) && preg_match('/^(?:0|[1-9]\d*)$/', $rawAfterId) === 1
                ? filter_var($rawAfterId, FILTER_VALIDATE_INT)
                : false;

            if ($afterId === false) {
                return '--after-id must be a non-negative integer within the outbox id range.';
            }
        }

        $workflowType = $input->getArgument('workflowType');

        try {
            return new RedriveBatchScope(is_string($workflowType) ? $workflowType : '', (int) $limit, $before, $since, $afterId);
        } catch (InvalidRedriveBatch $e) {
            return $e->getMessage();
        }
    }

    /**
     * The instant `$value` names, or null when it is not strict RFC 3339 with an offset or names a
     * calendar date that does not exist, which PHP would otherwise roll into the next month.
     */
    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match(self::INSTANT, $value) !== 1) {
            return null;
        }

        try {
            $instant = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $instant->format('Y-m-d\TH:i:s') === substr($value, 0, 19) ? $instant : null;
    }

    /**
     * @return array{id: int, workflow_type: string, correlation_id: string, outcome: string}
     */
    private static function line(RedriveCandidate $candidate, string $outcome): array
    {
        return ['id' => $candidate->id, 'workflow_type' => $candidate->workflowType, 'correlation_id' => $candidate->correlationId, 'outcome' => $outcome];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function emit(OutputInterface $output, array $line): void
    {
        $output->writeln(
            json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
