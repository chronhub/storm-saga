<?php

declare(strict_types=1);

namespace Storm\Saga\Console;

use JsonException;
use Override;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\SagaMaintenanceReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The version-pinning purge view: per workflow name, every registered version with its label crossed
 * with the count of running instances pinned to it. The operator reads it to know when an old version
 * has drained; zero running instances means its `#[Workflow]` class can be removed and redeployed.
 *
 * The purge protocol, since version pinning keeps old definitions alive on purpose:
 *  1. Deploy v2 alongside v1, co-registering both classes. New instances are born under v2 while the
 *     in-flight v1 instances keep running under v1.
 *
 *  2. Watch this command. While v1 shows running instances, keep its class.
 *
 *  3. When v1 reaches zero running, remove the v1 class and redeploy; the version is drained.
 *
 * Only `running` instances are counted: a settled saga never advances, so it never resolves its
 * definition again, and purging its version cannot strand it. A version that still has running instances
 * but is no longer registered is an orphan, purged too early; its instances fail loud with
 * `WorkflowVersionNotFound` on their next step.
 *
 * `--check` is the deployment pre-flight gate: it exits non-zero if any running instance is pinned to an
 * unregistered version, so a CI or deploy step can refuse a cutover that would strand an in-flight saga.
 * Green means every running instance resolves to a registered version.
 *
 * Examples:
 *
 * ```bash
 * bin/console storm:saga:versions
 * bin/console storm:saga:versions --check
 * ```
 */
#[AsCommand(
    name: 'storm:saga:versions',
    description: 'List workflow versions × running-instance counts (the purge view); --check fails if a running instance is pinned to an unregistered version.',
)]
final class SagaVersionsCommand extends Command
{
    public function __construct(
        private readonly SagaMaintenanceReader $instances,
        private readonly WorkflowRegistry $registry,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable verdict and the orphaned pins');
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Exit non-zero if any running instance is pinned to an unregistered version (a deploy pre-flight — proves no purge stranded an in-flight saga).',
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws SagaStorageFailure on a saga-store read failure
     * @throws JsonException on encoding the --json output
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $registered = $this->registered();  // name => (version => label)
        $running = $this->instances->runningCountsByVersion();  // type => (version => count)

        $rows = [];
        $orphans = [];
        foreach ($this->sortedKeys($registered, $running) as $name) {
            foreach ($this->sortedVersions($registered[$name] ?? [], $running[$name] ?? []) as $version) {
                $isRegistered = array_key_exists($version, $registered[$name] ?? []);
                $count = $running[$name][$version] ?? 0;

                if (! $isRegistered && $count > 0) {
                    $orphans[] = ['type' => $name, 'version' => $version, 'count' => $count];
                }

                $rows[] = [
                    $name,
                    (string) $version,
                    $registered[$name][$version] ?? '—',
                    $isRegistered ? 'yes' : '<error>NO</error>',
                    $count > 0 ? (string) $count : '<comment>0</comment>',
                ];
            }
        }

        if ($input->getOption('json') === true) {
            // a deployment gate is scripted by definition: the verdict rides the exit code, the
            // orphaned pins ride the document, and neither has to be scraped out of an error block.
            // The document is served in both modes, `--check` deciding only the exit
            $output->writeln(json_encode(
                ['deployable' => $orphans === [], 'orphans' => $orphans],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $input->getOption('check') === true && $orphans !== [] ? Command::FAILURE : Command::SUCCESS;
        }

        if ($input->getOption('check')) {
            return $this->check($io, $orphans);
        }

        if ($rows === []) {
            $io->warning('No workflows registered and no running instances.');

            return Command::SUCCESS;
        }

        $io->table(['workflow', 'version', 'label', 'registered', 'running'], $rows);

        if ($orphans !== []) {
            $orphans
                |> count(...)
                |> (static fn ($x) => sprintf('%d version(s) have running instances but are NOT registered — a purged-too-early version strands them. Gate this with --check in your deploy.', $x))
                |> $io->warning(...);
        } else {
            $io->writeln('<info>A registered version showing zero running instances has drained — its class can be removed and redeployed.</info>');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<array{type: string, version: int, count: int}>  $orphans
     */
    private function check(SymfonyStyle $io, array $orphans): int
    {
        if ($orphans === []) {
            $io->success('Every running instance resolves to a registered version — safe to deploy.');

            return Command::SUCCESS;
        }

        foreach ($orphans as $orphan) {
            $io->error(sprintf(
                '%d running instance(s) of "%s" are pinned to version %d, which is NOT registered (purged too early — they would fail loud on their next step).',
                $orphan['count'], $orphan['type'], $orphan['version'],
            ));
        }

        return Command::FAILURE;
    }

    /**
     * @return array<string, array<int, string|null>> name => version => label
     */
    private function registered(): array
    {
        $map = [];
        foreach ($this->registry->all() as $definition) {
            $map[$definition->name][$definition->version] = $definition->label;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $registered
     * @param  array<string, mixed>  $running
     * @return list<string>
     */
    private function sortedKeys(array $registered, array $running): array
    {
        $names = array_keys($registered + $running);
        sort($names);

        return $names;
    }

    /**
     * @param  array<int, mixed>  $registered
     * @param  array<int, mixed>  $running
     * @return list<int>
     */
    private function sortedVersions(array $registered, array $running): array
    {
        $versions = array_keys($registered + $running);
        sort($versions);

        return $versions;
    }
}
