<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use Override;
use Storm\Saga\Build\WorkflowRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The deployment gate of a lazily assembled registry: assembles EVERY declared workflow graph and
 * reports every one that refuses, not merely the first.
 *
 * The registry assembles a graph at its first resolution, so a malformed declaration no longer
 * announces itself when the engine boots; it waits for the request that touches it. This command is
 * what puts that verdict back before production, and it belongs in the CI or preflight of the
 * application that DECLARES the workflows, since that is where the hundreds of them live. The
 * framework can ship the gate; it cannot run the consumer's pipeline.
 *
 * It resolves pair by pair rather than through `all()`, and that is the difference that matters: a
 * single traversal reports every broken workflow, so one deploy attempt yields the whole repair list
 * instead of one item per round trip. A failure is contained per pair, never allowed to end the walk.
 *
 * Exit code is the contract for a pipeline: `0` when every declared pair assembled, `1` when at least
 * one refused.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:validate
 * ```
 *
 * ```bash
 * bin/console storm:saga:validate --quiet || echo 'workflow declarations are broken'
 * ```
 */
#[AsCommand(
    name: 'storm:saga:validate',
    description: 'Assemble every declared workflow definition and report all failures; the deployment gate of the lazy registry.',
)]
final class ValidateSagaCommand extends Command
{
    public function __construct(
        private readonly WorkflowRegistry $registry,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     *
     * Every assembly failure is caught, whatever its type: a declaration refusal, an unresolvable
     * activity, a container error on the workflow service itself. Narrowing the catch would let one
     * unexpected shape end the walk and hide the pairs behind it, which is the one thing this command
     * exists not to do.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $checked = 0;
        $failures = [];

        foreach ($this->registry->declared() as $name => $versions) {
            foreach ($versions as $version) {
                $checked++;

                try {
                    $this->registry->get($name, $version);
                } catch (Throwable $e) {
                    $failures[] = [$name, (string) $version, $e::class, $e->getMessage()];
                }
            }
        }

        if ($checked === 0) {
            $io->success('No workflow is declared: nothing to validate.');

            return Command::SUCCESS;
        }

        if ($failures !== []) {
            $io->table(['workflow', 'version', 'refusal', 'message'], $failures);
            $io->error(sprintf(
                '%d of %d declared workflow definition(s) failed to assemble. Every failure is listed above; fix them all before deploying.',
                count($failures),
                $checked,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('All %d declared workflow definition(s) assembled.', $checked));

        return Command::SUCCESS;
    }
}
