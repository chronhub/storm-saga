<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\FailedWorkflowCommands;
use Storm\Saga\Outbox\RedriveOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send a dead-lettered command back into flight, for the saga that did nothing wrong.
 *
 * A command dies of causes that are usually NOT the saga's: a handler that was not deployed, a broker
 * misrouted, a bug since fixed. The alternative verb, `storm:saga:cancel`, kills a healthy saga
 * because one of its commands met a bad afternoon; this re-sends the command instead.
 *
 * The correlation and the message id are what `storm:saga:inspect` prints in the outbox table, along
 * with the `evidence` that decides whether this is safe at all.
 *
 * Four guards, and the store applies them all inside one statement, never as a read followed by a
 * write:
 *
 * - The row is still dead-lettered;
 * - The saga still runs;
 * - The row belongs to the saga's CURRENT run;
 * - The effect is proven uncommitted.
 *
 * Each refusal is named: an operator told only "nothing happened" goes and edits SQL by hand, which
 * is exactly what this command exists to prevent.
 *
 * On `--force`: only the evidence guard can be lifted, and lifting it means accepting that the
 * handler may have committed before it threw, so the command may execute TWICE. It exists because
 * `unknown` is the honest verdict for most consumer-side failures, not a rare one, and a repair that
 * refuses every real case is not a repair. It is not silent: the reason is required with it.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:inspect <correlation-id>          # read the message id and its evidence
 * bin/console storm:saga:redrive <correlation-id> <message-id>
 * bin/console storm:saga:redrive <correlation-id> <message-id> --force --reason="handler redeployed, charge never landed"
 * ```
 */
#[AsCommand(
    name: 'storm:saga:redrive',
    description: 'Re-send a dead-lettered saga command — the repair that does not kill the saga.',
)]
final class SagaRedriveCommand extends Command
{
    public function __construct(
        private readonly FailedWorkflowCommands $outbox,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('correlationId', InputArgument::REQUIRED, 'The saga correlation id');
        $this->addArgument('messageId', InputArgument::REQUIRED, 'The dead-lettered command id, as printed by storm:saga:inspect');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-send even when the effect is not proven uncommitted — it may execute twice');
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, echoed on the success line; required with --force. The durable record of WHO decided lives on the HTTP twin, which has an ops identity to attribute it to');
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
        $correlation = (string) $input->getArgument('correlationId');
        $messageId = (string) $input->getArgument('messageId');
        $force = $input->getOption('force') === true;
        $reason = $input->getOption('reason');

        $stated = is_string($reason) ? trim($reason) : '';

        if ($force && $stated === '') {
            // a forced double-effect risk with no stated why is an unattributable decision: whoever
            // reads the log afterwards must find the judgement, not just the act
            $io->error('--force requires --reason: re-sending an unproven effect is a decision, and it has to be attributable.');

            return Command::INVALID;
        }

        $outcome = $this->outbox->redrive($correlation, $messageId, $force);

        if ($outcome->applied()) {
            $io->success(sprintf(
                'Command %s of saga %s is back in flight%s.',
                $messageId,
                $correlation,
                $force ? sprintf(' (forced — %s)', $stated) : '',
            ));

            return Command::SUCCESS;
        }

        $io->warning(self::explain($outcome));

        return Command::FAILURE;
    }

    /**
     * The refusal in the operator's terms, and what to do about it; the whole reason the store
     * returns a sealed verdict rather than a boolean.
     */
    private static function explain(RedriveOutcome $outcome): string
    {
        return match ($outcome) {
            RedriveOutcome::NotFound => 'No such command for this correlation — check the message id printed by storm:saga:inspect, or the row may have been pruned.',
            RedriveOutcome::NotDeadLettered => 'That command is not dead-lettered: pending is already in flight, published succeeded, cancelled was recalled by an aborting settle. None of them is re-sent.',
            RedriveOutcome::SagaNotRunning => 'The saga has settled or is gone. Re-sending would put an effect in flight that nothing will ever receive.',
            RedriveOutcome::StaleGeneration => 'That command belongs to an EARLIER run of this correlation. It must never cross into the run that replaced it.',
            RedriveOutcome::EffectUnproven => 'Nobody proved this effect rolled back, so re-sending may execute it twice. Establish what happened downstream, then pass --force --reason to own the risk.',
            RedriveOutcome::Raced => 'The row moved while this ran — a settle or another operator got there first. Re-read it with storm:saga:inspect and try again.',
            RedriveOutcome::Redriven => 'Redriven.', // unreachable: applied() returned early
        };
    }
}
