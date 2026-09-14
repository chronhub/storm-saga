<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Build\WorkflowBuilder;
use Storm\Saga\Build\WorkflowIndex;
use Storm\Saga\Build\WorkflowMetadata;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Console\ValidateSagaCommand;
use Storm\Saga\Tests\Fixture\AuditActivity;
use Storm\Saga\Tests\Fixture\AuditWorkflow;
use Storm\Saga\Tests\Fixture\BrokenTransitionWorkflow;
use Storm\Saga\Tests\Fixture\CountingContainer;
use Storm\Saga\Tests\Fixture\MissingActivityWorkflow;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The deployment gate judged on the one property that makes it a gate rather than a smoke test: it
 * reports EVERY broken declaration in a single pass.
 *
 * Stopping at the first refusal would turn a repair into one deploy attempt per broken workflow,
 * which is why the suite always feeds it two faults of DIFFERENT natures, a graph that names a state
 * nobody declared and a graph whose activity is not registered, and demands both names in one output.
 * The other property pinned here is reach: the gate resolves declarations the runtime was never asked
 * for, which is exactly the class of fault an on-demand registry would otherwise leave sleeping until
 * production.
 */
final class ValidateSagaCommandTest extends TestCase
{
    #[Test]
    public function reports_every_broken_declaration_in_one_pass(): void
    {
        $tester = new CommandTester(new ValidateSagaCommand($this->registry([
            AuditWorkflow::class,
            BrokenTransitionWorkflow::class,
            MissingActivityWorkflow::class,
        ])));

        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('broken_transition', $display);
        self::assertMatchesRegularExpression('/^\s*workflow\s+version\s+refusal\s+message\s*$/m', $display);
        self::assertStringContainsString('version', $display);
        self::assertStringContainsString('refusal', $display);
        self::assertStringContainsString('message', $display);
        self::assertStringContainsString('missing_activity', $display);
        self::assertStringContainsString('2 of 3 declared workflow definition(s) failed', $display);
    }

    #[Test]
    public function a_declaration_the_runtime_never_asks_for_is_still_assembled(): void
    {
        // nothing resolved this name before the gate ran: its fault has no other way to surface short
        // of the request that would first touch it in production
        $tester = new CommandTester(new ValidateSagaCommand($this->registry([BrokenTransitionWorkflow::class])));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('broken_transition', $tester->getDisplay());
    }

    #[Test]
    public function a_healthy_catalogue_passes(): void
    {
        $tester = new CommandTester(new ValidateSagaCommand($this->registry([AuditWorkflow::class])));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All 1 declared workflow definition(s) assembled.', $tester->getDisplay());
    }

    #[Test]
    public function an_empty_catalogue_is_not_a_failure(): void
    {
        // an application that declares no saga has nothing to gate, and a red pipeline would be a lie
        $tester = new CommandTester(new ValidateSagaCommand(new WorkflowRegistry));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No workflow is declared', $tester->getDisplay());
        self::assertStringNotContainsString('All 0 declared', $tester->getDisplay());
    }

    #[Test]
    public function a_pass_over_a_healthy_catalogue_leaves_every_definition_resolvable(): void
    {
        $registry = $this->registry([AuditWorkflow::class]);

        new CommandTester(new ValidateSagaCommand($registry))->execute([]);

        self::assertSame('audit', $registry->get('audit')->name);
    }

    /**
     * The wiring the compiler pass produces, in miniature: the index is READ off the classes, so the
     * table and the graphs can never drift apart the way a hand-written table would.
     *
     * @param  list<class-string>  $workflows
     */
    private function registry(array $workflows): WorkflowRegistry
    {
        $declared = [];
        $factories = [];

        foreach ($workflows as $class) {
            $metadata = WorkflowMetadata::fromClass($class);
            $declared[] = $metadata;
            $factories[$metadata->key()] = static fn (): object => new $class;
        }

        return WorkflowRegistry::lazy(
            new WorkflowIndex($declared),
            new CountingContainer($factories),
            new WorkflowBuilder(new CountingContainer([
                AuditActivity::class => static fn (): AuditActivity => new AuditActivity,
            ])),
        );
    }
}
