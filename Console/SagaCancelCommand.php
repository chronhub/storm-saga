<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\Contracts\Serializer\SerializationExceptionContract;
use Storm\Saga\Engine\Engine;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The operator's stop button: cancel one saga instance, halting it where it sits and rolling back what
 * is safe to undo by positional eligibility; an unconfirmed in-flight step is skipped and flagged, never
 * blindly compensated. Refused at an effect-gating wait unless `--force`: an in-flight effect is never
 * discarded on a word alone; retry after its outcome lands, or own the risk explicitly.
 * A busy step fence refuses cancellation even with `--force`; retry after the concurrent step finishes.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:cancel <workflow-type> <correlation-id> --reason "duplicate order"
 * bin/console storm:saga:cancel <workflow-type> <correlation-id> --force # at an effect-gating wait, owning the risk
 * ```
 */
#[AsCommand(
    name: 'storm:saga:cancel',
    description: 'Cancel a saga instance: halt it and compensate what is safe to undo',
)]
final class SagaCancelCommand extends Command
{
    public function __construct(
        private readonly Engine $engine,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('workflowType', InputArgument::REQUIRED, 'The workflow type (e.g. transfer)');
        $this->addArgument('correlationId', InputArgument::REQUIRED, 'The instance correlation id');
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'The operator reason, announced on SagaCancelled (audit trail)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Cancel even at an effect-gating wait (the in-flight step is still skipped+flagged, never blindly compensated)');
    }

    /**
     * {@inheritDoc}
     *
     * @throws WorkflowNotFound when the workflow type is not registered
     * @throws WorkflowVersionNotFound when the instance pins a version no longer registered
     * @throws StaleWorkflowInstance when the cancel loses to a competing step; retry the command
     * @throws ClockExceptionContract when a compensation timestamp cannot be derived
     * @throws SerializationExceptionContract when a compensation's issued command is not a serializable payload
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable from the transaction fence or a post-commit event listener; a failing compensation handler does NOT escape, it is recorded as CompensationFailed
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = (string) $input->getArgument('workflowType');
        $correlationId = (string) $input->getArgument('correlationId');
        /** @var string|null $reason */
        $reason = $input->getOption('reason');
        $force = (bool) $input->getOption('force');

        if ($this->engine->cancel($type, $correlationId, $reason, $force)) {
            $io->success(sprintf('Saga %s/%s cancelled — halted and compensated what was safe to undo.', $type, $correlationId));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Saga %s/%s was NOT cancelled — it does not exist, already settled, is behind a busy step fence, '
            .'or sits at an effect-gating wait. For a busy step fence, retry after the concurrent step finishes; '
            .'--force does not bypass the fence. At an effect-gating wait, retry after the outcome lands '
            .'or re-run with --force to own the risk.',
            $type, $correlationId,
        ));

        return Command::FAILURE;
    }
}
