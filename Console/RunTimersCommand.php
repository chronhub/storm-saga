<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Saga\Schedule\TimerRunner;
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
 * Drains due saga timers: fires timeouts and re-runs kicks, the saga recovery agent. The claim is leased,
 * so a crashed run is retried on the next pass.
 *
 * One-shot by default, draining once then exiting so a scheduler re-runs it; `--daemon` keeps it up and
 * loops the drain in-process, so the framework bootstrap is paid once, not per tick via {@see \Storm\Support\Console\DaemonLoop}.
 * Run the daemon under the prod environment; a long-lived dev kernel accumulates debug collectors without
 * bound.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:timers
 * bin/console storm:saga:timers --batch=500
 * bin/console storm:saga:timers --daemon --sleep=500 # recycles after 3600 s by default
 * ```
 *
 * @see \Storm\Chronicler\Console\RelayOutboxCommand
 */
#[AsCommand(
    name: 'storm:saga:timers',
    description: 'Drain due saga timers once: fire timeouts and re-run kicks.',
)]
final class RunTimersCommand extends Command
{
    use DaemonLoop;

    public function __construct(
        private readonly TimerRunner $runner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max timers to claim in one run', '100');
        $this->configureDaemon();
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable on a DBAL failure claiming timers, or from driving a due timer into the engine,
     *                   surfaced to the console runner; the lease re-claims the row on a later tick
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batch = PositiveIntOption::parse($input->getOption('batch'));
        if ($batch === null) {
            $io->error('--batch must be a positive integer (max timers per claim), e.g. --batch=500.');

            return Command::INVALID;
        }

        if ((bool) $input->getOption('daemon')) {
            return $this->daemon($io, $input, $batch);
        }

        $tick = $this->runner->tick($batch);
        // the phrase is kept intact: the drain contract of the runbook reads it, and so do its tests
        $io->success(sprintf('Processed %d due saga timer(s).', $tick->processed));

        if ($tick->moreDue) {
            $io->warning('The batch was full, so more timers are due — re-run until this reports 0. Running timers also CREATES relay work, so drain the relays after.');
        }

        return Command::SUCCESS;
    }

    /**
     * Daemon mode: boot once, loop the tick in-process until SIGTERM/SIGINT or `--time-limit`. Each pass is an
     * independent leased {@see \Storm\Saga\Schedule\TimerRunner::tick()}, so looping is just repeated claims.
     *
     * @param  int<1, max>  $batch  positive; execute() guards it before the daemon branch
     */
    private function daemon(SymfonyStyle $io, InputInterface $input, int $batch): int
    {
        $processed = 0;

        $this->daemonLoop(function () use ($batch, &$processed): int {
            $n = $this->runner->tick($batch)->processed;
            $processed += $n;

            return $n;
        }, $this->daemonSleepMs($input), $this->daemonTimeLimit($input));

        $io->success(sprintf('Saga timers daemon stopped — processed %d due timer(s).', $processed));

        return Command::SUCCESS;
    }
}
