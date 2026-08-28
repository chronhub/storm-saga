<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Event\SagaPaused;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowPauses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator freeze: no NEXT step runs. With a correlation, one instance is stamped: `paused_at`,
 * its reason, the status still `running`, since a paused saga is a living one that is not executed.
 * Without one, the whole TYPE is registered in `workflow_pauses`, gating execution AND births.
 *
 * Either way:
 *
 * - The verb is a gate, not a barrier: a step that read its pause check before the stamp landed
 *   still completes and persists; the stamp survives that step untouched, every step after it reads
 *   the stamp under its fence, and the commands it wrote wait out the freeze in the outbox,
 *   dispatched after the resume.
 *
 * - State timers stay due at their original instants, unclaimed.
 *
 * - Facts addressed to the saga ride the retryable channel.
 *
 * - The global deadline and the cancel verb pass THROUGH, since a pause is not immortality and an
 *   operator must be able to kill what an operator froze.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:pause <workflow-type> <correlation-id> --reason "incident 4213"
 * bin/console storm:saga:pause <workflow-type> --reason "incident 4213" # the whole type, births included
 * ```
 *
 * @see SagaResumeCommand the other half
 */
#[AsCommand(
    name: 'storm:saga:pause',
    description: 'Freeze one saga instance (type + correlation) or a whole workflow type (type alone, births included) until storm:saga:resume',
)]
final class SagaPauseCommand extends Command
{
    public function __construct(
        private readonly WorkflowPauses $pauses,
        private readonly WorkflowInstances $instances,
        private readonly EventDispatcherInterface $events,
        private readonly WorkflowRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('workflowType', InputArgument::REQUIRED, 'The workflow type to freeze');
        $this->addArgument('correlationId', InputArgument::OPTIONAL, 'One instance to freeze; omitted, the WHOLE type is frozen, births included');
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why: the freeze is an operator decision and it has to be attributable');
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
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the option being mixed; the guard above already proved it is not null and a console option is a string
        $reason = $input->getOption('reason') === null ? null : (string) $input->getOption('reason');

        if ($correlation === null) {
            // The identity pair reads `<correlation> [type]` on inspect and history, and `<type>
            // [correlation]` here, so a single argument lands on the TYPE branch whatever the operator
            // meant. Unvalidated, a correlation id typed in that position registered a pause for a type
            // that does not exist, answered success, and left the saga it was meant to hold running;
            // the mistake surfaced only at the lift, as a second misleading message.
            if (! $this->registry->has($type)) {
                $io->error(sprintf('Unknown workflow type "%s". Registered types are listed by storm:saga:versions. To freeze one instance, pass its type first, then its correlation id.', $type));

                return Command::INVALID;
            }

            $this->pauses->pauseType($type, $reason);
            $io->success(sprintf('Workflow type "%s" is frozen: no next step runs and no instance is born until storm:saga:resume %s. A step already in flight completes, its commands holding in the outbox; in-flight facts redeliver and may dead-letter at their cap; redrive them after the resume.', $type, $type));

            return Command::SUCCESS;
        }

        $id = new WorkflowId($type, (string) $correlation);
        if (! $this->pauses->pauseInstance($id, $reason)) {
            $io->warning(sprintf('Nothing frozen: "%s/%s" is absent, settled, or already paused.', $type, (string) $correlation));

            return Command::FAILURE;
        }

        $row = $this->instances->find($id);
        $this->events->dispatch(new SagaPaused($type, (string) $correlation, $row->generation ?? 0, $reason));
        $io->success(sprintf('Saga "%s/%s" is frozen: still a LIVING instance, no next step until storm:saga:resume; one already in flight completes, its commands holding in the outbox. Its state timers keep their original instants; the global deadline still enforces.', $type, (string) $correlation));

        return Command::SUCCESS;
    }
}
