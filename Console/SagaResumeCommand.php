<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Event\SagaResumed;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowPauses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lift the operator freeze: the instance stamp cleared, or the type row deleted from
 * `workflow_pauses`. The resumed sagas' due timers are claimable again AT THEIR ORIGINAL INSTANTS,
 * so a deadline that expired during the window fires at the next cycle; the pause never
 * manufactured time the business did not grant. Facts that dead-lettered during a long window are
 * `storm:saga:redrive`'s to send back.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:resume <workflow-type> <correlation-id>
 * bin/console storm:saga:resume <workflow-type> # lift the TYPE registry row, births included
 * ```
 *
 * @see SagaPauseCommand the other half
 */
#[AsCommand(
    name: 'storm:saga:resume',
    description: 'Lift the freeze of one saga instance (type + correlation) or of a whole workflow type (type alone)',
)]
final class SagaResumeCommand extends Command
{
    public function __construct(
        private readonly WorkflowPauses $pauses,
        private readonly WorkflowInstances $instances,
        private readonly EventDispatcherInterface $events,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('workflowType', InputArgument::REQUIRED, 'The workflow type to unfreeze');
        $this->addArgument('correlationId', InputArgument::OPTIONAL, 'One instance to unfreeze; omitted, the TYPE registry row is lifted');
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure when the saga storage fails
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the console argument being mixed; it is a string by the time it arrives
        $type = (string) $input->getArgument('workflowType');
        $correlation = $input->getArgument('correlationId');

        if ($correlation === null) {
            if (! $this->pauses->resumeType($type)) {
                $io->warning(sprintf('Nothing lifted: workflow type "%s" was not frozen.', $type));

                return Command::FAILURE;
            }
            $io->success(sprintf('Workflow type "%s" resumed: steps run, births are accepted, held commands dispatch, and timers overdue from the window fire at the next cycle. Check the failed queue for facts the window dead-lettered.', $type));

            return Command::SUCCESS;
        }

        $id = new WorkflowId($type, (string) $correlation);
        if (! $this->pauses->resumeInstance($id)) {
            $io->warning(sprintf('Nothing lifted: "%s/%s" is absent or was not paused.', $type, (string) $correlation));

            return Command::FAILURE;
        }

        $row = $this->instances->find($id);
        $this->events->dispatch(new SagaResumed($type, (string) $correlation, $row->generation ?? 0));
        $io->success(sprintf('Saga "%s/%s" resumed: executable again, its held commands dispatching and its overdue timers firing at the next cycle at their original instants.', $type, (string) $correlation));

        return Command::SUCCESS;
    }
}
