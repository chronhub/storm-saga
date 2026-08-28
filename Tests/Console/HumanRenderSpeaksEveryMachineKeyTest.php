<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Store\Inspection\SagaSnapshot;
use Storm\Saga\Store\Inspection\SagaSummarySnapshot;
use Storm\Saga\Store\Inspection\ZombieChildSnapshot;

/**
 * Every key a snapshot serves on the wire is either rendered to a human or listed here as a
 * deliberate omission, with the reason it is one.
 *
 * The class of defect this closes, rather than its instances: a field lands on a snapshot, `toArray()`
 * carries it, `--json` is honest, and the human renderer never learns about it. The operator reading
 * the channel a human reads during an incident is the one who does not see it. That is how the
 * operator freeze stayed invisible in both saga reads while both HTTP twins carried it and both wrote
 * the invariant into their own docblocks.
 *
 * It cannot check that a key is rendered WELL, only that somebody decided. The decision is the point:
 * an omission has to be written down and argued, not arrived at by forgetting. The precedent is
 * {@see ReadmeNamesTheCommandsTest} and its reason, prose cannot be linted but a name can.
 */
final class HumanRenderSpeaksEveryMachineKeyTest extends TestCase
{
    /**
     * Keys the human channel deliberately does not render, and why.
     *
     * Adding a key here is a decision an author owes an argument for; a key that reaches neither list
     * fails the test until somebody makes that decision.
     *
     * @var array<class-string, array<string, string>>
     */
    private const array OMITTED = [
        SagaSummarySnapshot::class => [
            // the table is scanned across a park; a column nobody filters on costs width on every row
            'version' => 'the OCC token, an engine fact an operator never acts on',
            'started_at' => 'updated_at is the staleness question; the birth is inspect territory',
        ],
        SagaSnapshot::class => [
            'children' => 'walked by its own view; a nested tree in a flat render reads worse than a list',
            'state_version' => 'a migration axis, read by storm:saga:state:migrate and not by an operator',
        ],
        ZombieChildSnapshot::class => [],
    ];

    /**
     * Keys the renderer READS and does not print as their own field.
     *
     * The third kind, and it exists because the other two could not tell these apart from an omission:
     * a flag is a rendering, so its key is not omitted, but the column it would own is not there
     * either. Separating them is what lets the omission list be checked in the NEGATIVE, below.
     *
     * @var array<class-string, array<string, string>>
     */
    private const array RENDERED_AS = [
        SagaSummarySnapshot::class => [
            'parent_correlation_id' => 'the `child` flag, the fact without the id',
            'paused_at' => 'the `paused` flag, the fact without the stamp',
            'waived_at' => 'the `waived` flag, the fact without the stamp',
            'type_paused' => 'the `paused(type)` flag',
        ],
        SagaSnapshot::class => [],
        ZombieChildSnapshot::class => [],
    ];

    /**
     * The keys each renderer puts in front of a human.
     *
     * Written by hand, and therefore able to drift the way any inventory does; what holds it to the
     * code is the third check below, which reads the renderer's own source and refuses a claim the
     * source does not back. That check proves the datum is NAMED, never that it is shown well.
     *
     * @var array<class-string, list<string>>
     */
    private const array RENDERED = [
        SagaSummarySnapshot::class => [
            'workflow_type', 'correlation_id', 'state_key', 'status', 'updated_at', 'definition_version',
            'generation', 'retry_total',
        ],
        SagaSnapshot::class => [
            'workflow_type', 'state_key', 'status', 'version', 'started_at', 'definition_version',
            'generation', 'retry_total', 'retimes', 'updated_at', 'waived_at', 'paused_at',
            'paused_reason', 'type_paused', 'exposed', 'retries', 'compensations', 'timers', 'outbox',
            'parent_workflow_type', 'parent_correlation_id', 'root_correlation_id',
        ],
        ZombieChildSnapshot::class => [
            'workflow_type', 'correlation_id', 'parent_correlation_id', 'parent_status', 'started_at',
        ],
    ];

    /**
     * Every surface that puts a snapshot in front of a reader, by path from `src/Saga/`.
     *
     * A snapshot has more than one renderer, and the one that drifts is the one nobody listed: the
     * console and the HTTP twin read the same DTO, and only one of them is rewritten when a field
     * earns its place. Naming a PATH rather than importing a class is deliberate: the snapshots are
     * this module's, so the question "does every surface speak every key" is this module's too, and
     * asking it must not create a dependency the layer rules forbid.
     *
     * The zombie listing has no twin; the console is its only reader.
     *
     * @var array<class-string, string>
     */
    private const array RENDERERS = [
        SagaSummarySnapshot::class => 'Console/ListSagasCommand.php',
        SagaSnapshot::class => 'Console/InspectSagaCommand.php',
        ZombieChildSnapshot::class => 'Console/SagaChildrenCommand.php',
    ];

    /**
     * The structured twin of each snapshot, where one exists.
     *
     * Held to a stricter rule than the console, and the difference is the whole reason the two are
     * listed apart: a TABLE decides, because a column nobody filters on costs width on every row,
     * while a document has no width to pay and carries the row whole. So the console owes a decision
     * per key and the twin owes every key, full stop.
     *
     * Splitting them is what the omission check forced: indexed per SNAPSHOT, a decision taken for
     * the table read as a decision for the document too, and the twin serving a key the table
     * declined looked like a stale omission rather than the correct answer it was.
     *
     * @var array<class-string, string>
     */
    private const array STRUCTURED_TWIN = [
        SagaSummarySnapshot::class => '../ApiOps/Resource/SagaListingResource.php',
        SagaSnapshot::class => '../ApiOps/Resource/SagaResource.php',
    ];

    /**
     * @param  class-string  $snapshot
     * @param  array<string, mixed>  $machineShape
     */
    #[Test]
    #[DataProvider('snapshots')]
    public function every_machine_key_is_rendered_or_deliberately_omitted(string $snapshot, array $machineShape): void
    {
        $decided = [...self::RENDERED[$snapshot], ...array_keys(self::RENDERED_AS[$snapshot]), ...array_keys(self::OMITTED[$snapshot])];

        foreach (array_keys($machineShape) as $key) {
            self::assertContains(
                $key,
                $decided,
                sprintf(
                    '%s serves "%s" on the wire and the human render neither shows it nor declines it. '
                    .'Render it, or add it to OMITTED with the reason.',
                    $snapshot,
                    $key,
                ),
            );
        }
    }

    /**
     * @param  class-string  $snapshot
     * @param  array<string, mixed>  $machineShape
     */
    #[Test]
    #[DataProvider('snapshots')]
    public function no_decision_outlives_the_key_it_was_about(string $snapshot, array $machineShape): void
    {
        // the other direction: a key renamed or dropped leaves its entry standing, and the next author
        // reads a settled argument about a field that no longer exists
        foreach ([...self::RENDERED[$snapshot], ...array_keys(self::RENDERED_AS[$snapshot]), ...array_keys(self::OMITTED[$snapshot])] as $decided) {
            self::assertArrayHasKey(
                $decided,
                $machineShape,
                sprintf('%s no longer serves "%s"; drop the stale decision.', $snapshot, $decided),
            );
        }
    }

    /**
     * The inventory is held to the code it describes: every key claimed rendered is named by the
     * renderer's own source.
     *
     * Without it the two lists are a private opinion, and an opinion drifts silently in both
     * directions: a key listed as rendered that no renderer reads, and an omission argued on a
     * premise the source stopped honoring. Both had happened here, and neither showed up as a
     * failure, because the only thing under test was whether SOMEBODY had decided.
     *
     * A source read, not a rendering: it proves the datum reaches the renderer, never that it lands
     * where an operator looks. That limit is the same one the class docblock states, and it is why
     * an omission still owes its reason in prose.
     *
     * @param  class-string  $snapshot
     * @param  array<string, mixed>  $machineShape
     */
    #[Test]
    #[DataProvider('snapshots')]
    public function every_key_claimed_rendered_is_named_by_the_renderer(string $snapshot, array $machineShape): void
    {
        $renderer = self::RENDERERS[$snapshot];
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$renderer);

        self::assertNotSame('', $source, sprintf('the renderer %s is unreadable', $renderer));

        foreach ([...self::RENDERED[$snapshot], ...array_keys(self::RENDERED_AS[$snapshot])] as $key) {
            self::assertStringContainsString(
                '->'.self::accessor($key),
                $source,
                sprintf(
                    '%s lists "%s" as rendered, and %s never reads it. Render it, or move it to '
                    .'OMITTED with the reason it is not shown.',
                    $snapshot,
                    $key,
                    $renderer,
                ),
            );
        }
    }

    /**
     * The other direction, and the half that was missing: no key declared OMITTED is named by a
     * renderer.
     *
     * Checking only what is claimed rendered leaves the omission list unverifiable prose, and prose
     * is where a decision rots. Three entries here argued their omission on a lineage line no
     * renderer had ever written, and nothing could see it: the keys were correctly absent from the
     * output, so the positive check was satisfied, and the REASON was the only thing that was false.
     *
     * A key the renderer reads without printing its own field is neither rendered nor omitted; that
     * is what `RENDERED_AS` is for, and separating it is what makes this check exact rather than
     * noisy.
     *
     * @param  class-string  $snapshot
     * @param  array<string, mixed>  $machineShape
     */
    #[Test]
    #[DataProvider('snapshots')]
    public function no_key_declared_omitted_is_named_by_a_renderer(string $snapshot, array $machineShape): void
    {
        $renderer = self::RENDERERS[$snapshot];
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$renderer);

        // an empty omission list is a decision too, the one that declines nothing; asserting the
        // source read keeps that case from counting as a test with no assertion
        self::assertNotSame('', $source, sprintf('the renderer %s is unreadable', $renderer));

        foreach (array_keys(self::OMITTED[$snapshot]) as $key) {
            self::assertStringNotContainsString(
                '->'.self::accessor($key),
                $source,
                sprintf(
                    '%s declares "%s" omitted, and %s reads it. Either it reaches a reader, and it '
                    .'belongs in RENDERED or RENDERED_AS with what it becomes, or the read is dead.',
                    $snapshot,
                    $key,
                    $renderer,
                ),
            );
        }
    }

    /**
     * The structured twin carries EVERY key the wire carries.
     *
     * The rule the console does not get, and it is not laxity: a table pays width per column and a
     * document pays nothing, so the twin has no ground to decline. A twin short of a key is how a
     * refusal ends up pointing at a surface unable to answer it.
     *
     * @param  class-string  $snapshot
     * @param  array<string, mixed>  $machineShape
     */
    #[Test]
    #[DataProvider('snapshots')]
    public function the_structured_twin_carries_every_key(string $snapshot, array $machineShape): void
    {
        if (! isset(self::STRUCTURED_TWIN[$snapshot])) {
            self::assertArrayNotHasKey($snapshot, self::STRUCTURED_TWIN, 'no twin, nothing to hold');

            return;
        }

        $twin = self::STRUCTURED_TWIN[$snapshot];
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$twin);

        foreach (array_keys($machineShape) as $key) {
            self::assertStringContainsString(
                '->'.self::accessor($key),
                $source,
                sprintf('%s serves "%s" and its twin %s does not carry it.', $snapshot, $key, $twin),
            );
        }
    }

    /**
     * The wire key as the property a renderer reads: the snapshots are promoted-constructor DTOs, so
     * their camel-cased property is the wire key's own name.
     */
    private static function accessor(string $wireKey): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $wireKey))));
    }

    /**
     * @return iterable<string, array{class-string, array<string, mixed>}>
     */
    public static function snapshots(): iterable
    {
        yield 'listing row' => [SagaSummarySnapshot::class, new SagaSummarySnapshot(
            workflowType: 'transfer',
            correlationId: 'c-1',
            stateKey: 'await_credit',
            status: 'running',
            version: 1,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            startedAt: null,
            updatedAt: null,
            waivedAt: null,
            parentCorrelationId: null,
        )->toArray()];

        yield 'inspected saga' => [SagaSnapshot::class, new SagaSnapshot(
            workflowType: 'transfer',
            stateKey: 'await_credit',
            status: 'running',
            version: 1,
            startedAt: null,
            updatedAt: null,
            generation: 1,
            definitionVersion: 1,
            retryTotal: 0,
            waivedAt: null,
            retries: [],
            compensations: [],
            timers: [],
            outbox: [],
        )->toArray()];

        yield 'zombie child' => [ZombieChildSnapshot::class, new ZombieChildSnapshot(
            workflowType: 'leg',
            correlationId: 'c-2',
            parentCorrelationId: 'c-1',
            parentStatus: 'halted',
            startedAt: null,
        )->toArray()];
    }
}
