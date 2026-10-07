<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Closure;
use Override;
use Storm\Saga\Outbox\SagaOutboxDrainIncomplete;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Support\Console\DaemonLoop;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Drains the saga command outbox: dispatches pending commands to the command bus, then marks them
 * published. The saga command outbox is a separate stack from the event outbox by design.
 *
 * One-shot by default, draining once then exiting so a scheduler re-runs it; `--daemon` keeps it up and
 * loops the drain in-process, so the framework bootstrap is paid once, not per tick via {@see \Storm\Support\Console\DaemonLoop}.
 * Run the daemon under the prod environment; a long-lived dev kernel accumulates debug collectors without
 * bound.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:relay
 * bin/console storm:saga:relay --batch=500
 * bin/console storm:saga:relay --daemon --sleep=500 # recycles after 3600 s by default
 * ```
 *
 * @see \Storm\Chronicler\Console\RelayOutboxCommand
 */
#[AsCommand(
    name: 'storm:saga:relay',
    description: 'Drain the saga command outbox: dispatch pending commands, then mark them published.',
)]
final class RelaySagaOutboxCommand extends Command
{
    use DaemonLoop;

    public function __construct(
        private readonly SagaOutboxRelay $relay,
        /** @var Closure(): void|null */
        private readonly ?Closure $afterIteration = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max commands to drain in one run', '100');
        $this->configureDaemon();
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable from the daemon alone, on a DBAL failure draining the outbox or from the
     *                   post-commit saga settle of a dead-lettered command; the one-shot reports it
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (max commands per drain), e.g. --batch=500.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('daemon')) {
            return $this->daemon($io, $input, $batch);
        }

        try {
            $result = $this->iterate($batch);
        } catch (SagaOutboxDrainIncomplete $e) {
            // A transient dispatch failure: the relay fails FORWARD; what it reports here is what
            // it did, the commands it dispatched and the dead-letters it wrote, committed with the
            // back-off of every row it still held under its claim; the rest stays pending. Show that
            // progress, then signal the scheduler to retry, the same shape and the same phrase as
            // the event relay, so one alert rule reads both lanes.
            $io->error(sprintf(
                'Relayed %d saga command(s), %d dead-lettered, then stopped on a publish error: %s',
                $e->progress->published,
                $e->progress->failed,
                $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ));

            return Command::FAILURE;
        } catch (Throwable $e) {
            // the drain's own statements failed, nothing committed to report: the event relay's shape
            $io->error(sprintf('Saga outbox relay failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($result->failed > 0) {
            $io->warning(sprintf(
                'Relayed %d saga command(s); %d dead-lettered (status=failed).',
                $result->published,
                $result->failed,
            ));
        } else {
            // the phrase is kept intact: the runbook and its tests read it
            $io->success(sprintf('Relayed %d saga command(s).', $result->published));
        }

        $this->reportWhatIsLeft($io, $result->moreDue);

        return Command::SUCCESS;
    }

    /**
     * What the drain leaves behind, on EVERY path that succeeds.
     *
     * Every succeeding path reaches it, the dead-letter one included: a bad deployment that
     * dead-letters a full batch relays 0, and a gate that loops until a run reports 0 would read that
     * as an empty queue, counting what LEFT and never what remains.
     *
     * The two halves answer different questions. A full batch means the cap cut the run, so re-running
     * moves more immediately. A short batch means only that the cap did not cut it, and the table can
     * still hold commands no claim would take: backing off from an earlier run, held by another
     * worker, or frozen with their saga. None of those clear by looping, so the line names them rather
     * than inviting a hot loop.
     */
    private function reportWhatIsLeft(SymfonyStyle $io, bool $moreDue): void
    {
        if ($moreDue) {
            $io->warning('The batch was full, so more commands are pending — re-run until this reports 0.');

            return;
        }

        try {
            $pending = $this->relay->countPending();
        } catch (Throwable $e) {
            // the drain itself succeeded and is committed; a failed count must not restate that as an
            // error, it must only stop claiming the queue is empty
            $io->warning(sprintf('Could not read what is left pending, so the outbox is NOT proven drained: %s', $e->getMessage()));

            return;
        }

        if ($pending > 0) {
            $io->warning(sprintf(
                'The batch was not full, yet %d saga command(s) are still pending: backing off after a failed attempt, frozen with their saga, or held by another worker. The outbox is NOT drained; lift the freeze or wait out the back-off, then re-run.',
                $pending,
            ));
        }
    }

    /**
     * Daemon mode: boot once, loop the drain in-process until SIGTERM/SIGINT or `--time-limit`. Each pass is an
     * independent {@see \Storm\Saga\Outbox\SagaOutboxRelay::drain()} with its own transaction, so looping is just repeated drains.
     *
     * A {@see SagaOutboxDrainIncomplete}, a transient dispatch failure, is NOT fatal here: its progress
     * is committed and the relay's back-off is bumped, so the loop counts it as idle and polls before
     * retrying the tail. Any other Throwable propagates, so the process exits non-zero and the
     * supervisor respawns with back-off.
     */
    private function daemon(SymfonyStyle $io, InputInterface $input, int $batch): int
    {
        $published = 0;
        $failed = 0;

        $this->daemonLoop(function () use ($batch, &$published, &$failed): int {
            try {
                $result = $this->iterate($batch);
                $work = $result->published + $result->failed;
            } catch (SagaOutboxDrainIncomplete $e) {
                // The progress is committed and the back-off bumped; treat the tick as idle so the
                // poll honors it rather than spinning on an outage no loop can clear.
                $result = $e->progress;
                $work = 0;
            }

            $published += $result->published;
            $failed += $result->failed;

            return $work;
        }, $this->daemonSleepMs($input), $this->daemonTimeLimit($input));

        $io->success(sprintf(
            'Saga relay daemon stopped — relayed %d command(s), %d dead-lettered.',
            $published,
            $failed,
        ));

        return Command::SUCCESS;
    }

    private function iterate(int $batch): \Storm\Saga\Outbox\SagaOutboxDrainResult
    {
        try {
            return $this->relay->drain($batch);
        } finally {
            try {
                $this->afterIteration?->__invoke();
            } catch (Throwable) {
            }
        }
    }
}
