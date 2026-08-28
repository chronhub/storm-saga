<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\DueTimerQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Return a parked timer to the claim: the repair for a saga frozen by one poison row.
 *
 * A timer parks when its drive fails permanently or burns its attempts budget, which quarantines it
 * OUT of the claim so it cannot crash-loop the daemon. That protection is permanent by design: the
 * only other exits are a fresh `arm()` by the saga itself, which a saga waiting on that very timer
 * will never perform, or deleting the row. Without this repair, a cause that lived outside the row,
 * a downstream that was down or a bug since fixed, leaves the saga frozen for good.
 *
 * Safe by nature, which is why it takes no confirmation and stamps no evidence: a parked timer never
 * fired, so it committed no effect, and returning it to the claim cannot double one. It becomes
 * claimable again, and the attempts budget starts fresh; the failures of a previous life are not
 * this arm's, the same rule `arm()` already applies.
 *
 * The id is the one `storm:saga:inspect` prints in the timers table.
 *
 * Refuses rather than lying: a row that carries no park is not a repair, and exits FAILURE.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:inspect <correlation-id>   # read the parked timer's id
 * bin/console storm:saga:unpark 4821
 * ```
 */
#[AsCommand(
    name: 'storm:saga:unpark',
    description: 'Return a parked timer to the claim — the repair for a saga frozen by a poison timer.',
)]
final class SagaUnparkCommand extends Command
{
    public function __construct(
        private readonly DueTimerQueue $timers,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('timerId', InputArgument::REQUIRED, 'The timer row id, as printed by storm:saga:inspect');
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the storage refuses the update
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $raw = $input->getArgument('timerId');

        if (! is_string($raw) || ! ctype_digit($raw)) {
            $io->error('The timer id must be a positive integer, as printed by storm:saga:inspect.');

            return Command::INVALID;
        }

        if (! $this->timers->unpark((int) $raw)) {
            // the two misses share an exit because they share a meaning: nothing was repaired. Saying
            // "done" here would send an operator away believing a frozen saga was freed
            $io->warning(sprintf('Timer %s is not parked, or does not exist — nothing was returned to the claim.', $raw));

            return Command::FAILURE;
        }

        $io->success(sprintf('Timer %s returned to the claim, with a fresh attempts budget.', $raw));

        return Command::SUCCESS;
    }
}
