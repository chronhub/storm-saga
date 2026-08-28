<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Clock\Duration;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\Dbal\DbalWorkflowOutboxWriter;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Periodic saga maintenance: first reconcile stranded sagas, then prune terminal bookkeeping older than
 * `--before`. A saga's effects live in the event store, which is the truth; this only ever touches the
 * framework's bookkeeping. Destructive and idempotent; re-running finds the rows gone.
 *
 * Reconcile runs always and age-independent; the waived-and-quiet sagas are SURFACED alongside it, never
 * settled per inv. 2. Both are durable backstops for a signal the runtime raises in-process, each naming
 * its own at its method. Folded into this periodic command rather than a standalone `saga:reconcile` or
 * an inline scan on the hot relay path: a stranded saga is a rare crash-window event, so one scheduled
 * job suffices.
 *
 * Prune is two independent, terminal-scoped, batched DELETEs, so they never take a long lock:
 *  - `workflow_instances`: `status = 'completed'` only by default; `--include-failed` also prunes
 *    `halted` and `compensated`, which are kept by default for forensics. A `running` saga is never
 *    touched.
 *
 *  - `workflow_outbox`: `published` and `cancelled` rows purely by age, audit-only the moment they are
 *    stamped, whether relayed or recalled before dispatch; a running saga never re-reads its own outbox,
 *    so its instance's state is irrelevant. Under `command_outbox.disposal: archive` the aged-out
 *    `published` rows are MOVED to the cold `workflow_outbox_archive` instead of deleted, the issued-command
 *    trail kept out of the hot path; under `delete` they are dropped. A `failed` row is pruned by age ONLY
 *    once its saga is no longer running: while it runs, the row is the reconcile input above, so it always
 *    survives. A `pending` row is a message still in flight, never touched; this cannot race the live
 *    relay, which works on `pending`.
 *
 * Run periodically under a scheduler; overlap prevention is the scheduler's job, as this command holds no
 * single-instance lock. Backing up before pruning is the operator's call; Storm does not back up.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:cleanup --before 30d
 * bin/console storm:saga:cleanup --before 90d --include-failed
 * bin/console storm:saga:cleanup --before 30d --dry-run # count only, deletes nothing
 * ```
 */
#[AsCommand(
    name: 'storm:saga:cleanup',
    description: 'Prune terminal saga bookkeeping older than --before (completed instances + published outbox commands).',
)]
final class SagaCleanupCommand extends Command
{
    public function __construct(
        private readonly DbalWorkflowInstanceStore $instances,
        private readonly DbalWorkflowOutboxWriter $outbox,
        /**
         * The ROLE, not the final `Engine`: reconcile only ever needs the operator contract, and
         * depending on the concrete class makes the money-path branch untestable, since a final readonly
         * class cannot be doubled. The container resolves it through the role alias to `Engine`.
         *
         * Optional so the prune-only tests construct the command standalone; the bundle autowires it in
         * production. Without it, reconcile is skipped and only the prune runs.
         */
        private readonly ?SagaOperator $engine = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('before', null, InputOption::VALUE_REQUIRED, 'Prune rows older than this (e.g. 30d, 48h, 90m; m is minutes) — required');
        $this->addOption('include-failed', null, InputOption::VALUE_NONE, 'Also prune halted / compensated instances (default: completed only)');
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows deleted per batch', '1000');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be pruned, delete nothing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the reconcile scan or a prune count / delete fails; a
     *                            settle's own failure, its storage, version, clock and
     *                            serialization tails included, never escapes the per-item
     *                            isolation, it is reported and turned into the FAILURE exit
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $before = $input->getOption('before');
        $duration = Duration::fromString(is_string($before) ? $before : '');
        if ($duration === null) {
            $io->error('Specify --before with an age, e.g. --before 30d (suffixes: d=days, h=hours, m=minutes).');

            return Command::INVALID;
        }
        $age = $duration->seconds;

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (rows deleted per batch), e.g. --batch=1000.');

            return Command::INVALID;
        }
        $includeFailed = $input->getOption('include-failed') === true;
        $dryRun = $input->getOption('dry-run') === true;

        $io->title(sprintf('Saga cleanup — terminal rows older than %s%s', $input->getOption('before'), $dryRun ? ' (DRY RUN)' : ''));
        $io->writeln(sprintf('  instances: %s · outbox: published + cancelled by age, failed once the saga settled', $includeFailed ? 'completed + halted + compensated' : 'completed only'));

        // always reconcile first as the durable backstop, age-independent; then prune retention rows.
        [$stranded, $strandedFailures] = $this->reconcile($dryRun, $batch, $io);
        $this->reportWaived($io);

        if ($dryRun) {
            $instances = $this->instances->countTerminal($includeFailed, $age);
            $outbox = $this->outbox->countPrunable($age);
            // the real run deletes THREE ways, and the archive is the issued-command trail that exists
            // nowhere else: a preview counting two of them said "nothing changed" over a destructive arm
            $archived = $this->outbox->countArchive($age);
            $io->success(sprintf(
                'Would reconcile %d stranded saga(s) + prune %d instance(s) + %d outbox row(s) + %d archived row(s). Nothing changed.',
                $stranded,
                $instances,
                $outbox,
                $archived,
            ));

            return Command::SUCCESS;
        }

        $instances = $this->instances->pruneTerminal($includeFailed, $age, $batch);
        // prune reaps the hot table by age: deletes the terminal rows, or, under `disposal: archive`, MOVES
        // the aged-out published command trail to the cold archive and deletes the rest.
        $outbox = $this->outbox->prune($age, $batch);
        // then the cold archive sweeps by the same age; a no-op under `disposal: delete` since the archive is empty
        $archived = $this->outbox->pruneArchive($age, $batch);
        $io->success(sprintf('Reconciled %d stranded saga(s); pruned %d instance(s) + %d outbox row(s) + %d archived row(s).', $stranded, $instances, $outbox, $archived));

        if ($strandedFailures > 0) {
            // the prunes still ran: a poisoned settle must not also block retention; the exit code
            // carries the truth so the scheduler surfaces the failing reconcile
            $io->error(sprintf('%d stranded settle(s) failed and were skipped; the next run retries them.', $strandedFailures));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Surface the WAIVED-and-quiet sagas, the durable backstop for the waive's non-durable
     * SagaAwaitOverdue announcement. A waived saga is `running` with zero timers by design, since the cap
     * was spent at a retriable gating wait and a late outcome still resolves it; nothing here may settle
     * it, an in-flight effect is never discarded per inv. 2, and resolution belongs to the app's
     * reconciliation. This sweep only makes the quiet ones VISIBLE: `waived_at` stamped, untouched for 5+
     * minutes; a saga actively advancing on a late outcome stays out of the report.
     */
    private function reportWaived(SymfonyStyle $io): void
    {
        $waived = $this->instances->waivedAndQuiet(quietForSeconds: 300);
        if ($waived === []) {
            return;
        }

        $io->section(sprintf('%d waived saga(s) parked quiet — awaiting a late outcome or app-side reconciliation', count($waived)));
        foreach ($waived as $row) {
            $io->writeln(sprintf(
                '  %s %s — at "%s" since %s (waived %s)',
                $row->workflowType, $row->correlationId, $row->stateKey,
                $row->startedAt?->toString() ?? '?', $row->waivedAt?->toString() ?? '?',
            ));
        }
    }

    /**
     * Reconcile stranded sagas, the durable backstop for the relay's non-durable settle signal. A
     * dead-lettered command leaves a durable `workflow_outbox` row at `status='failed'`; the relay then
     * settles the saga in-process after the drain commits, so a crash in that window loses the signal
     * and parks the saga at its effect-gating wait, an earlier confirmed effect never compensated. This
     * re-derives the durable truth of failed outbox rows whose saga is still running and replays
     * `failIssuedEffect`, idempotent, a no-op once the saga has moved past the wait.
     *
     * The pass is BOUNDED, ORDERED and isolated per item: the scan reads one deterministic page,
     * since the other producer of failed rows, the consumer-side dead-letter, is a MASS event and
     * not the rare crash window; and one throwing settle is recorded and skipped rather than
     * aborting the pass, so a persistent poison cannot decide, run after run, which sagas behind
     * it never get reconciled. Settled rows leave the result set, so repeated runs drain the
     * backlog page by page while the poison keeps failing loud.
     *
     * @param  positive-int  $batch
     * @return array{int, int} stranded sagas settled, or in dry-run the candidates found; and the settles that failed and were skipped
     *
     * @throws SagaStorageFailure when the stranded SCAN itself fails; a settle's own failure never
     *                            escapes, it is reported, counted, and turned into the FAILURE exit
     */
    private function reconcile(bool $dryRun, int $batch, SymfonyStyle $io): array
    {
        if ($this->engine === null) {
            return [0, 0]; // standalone with no engine wired, so prune-only
        }

        // the domain-meaningful port query; the money-path scan is testable against any adapter
        $stranded = $this->instances->strandedByFailedEffect($batch);

        if ($dryRun) {
            return [count($stranded), 0];
        }

        $settled = 0;
        $failed = 0;
        foreach ($stranded as [$correlationId, $messageId]) {
            try {
                if ($this->engine->failIssuedEffect($correlationId, failedMessageId: $messageId)->applied()) {
                    $settled++;
                }
            } catch (Throwable $e) {
                $failed++;
                $io->warning(sprintf('Settle of stranded saga "%s" (message %s) failed: %s — skipped, the next run retries it.', $correlationId, $messageId ?? '?', $e->getMessage()));
            }
        }

        return [$settled, $failed];
    }
}
