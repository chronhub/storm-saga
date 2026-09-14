<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Lookup of the workflow definitions by `(name, version)`, over a declaration index that is always
 * complete and a set of assembled graphs that need not be.
 *
 * Two regimes feed it, and they answer identically:
 *
 * - Assembled, `new WorkflowRegistry($definitions)`: every graph is already built, and the index is
 *   derived from them. What a test constructs by hand, and the shape `fromWorkflows()` produces.
 *
 * - Lazy, `lazy()`: the index arrives from a compiled declaration table and a graph is assembled at
 *   its first `get()`, then memoized. A process pays for the workflows it touches, not for the
 *   catalogue, which is what a shared-nothing request needs; a resident worker assembles each graph
 *   once and never again.
 *
 * The index is what keeps the two honest. The laws that compare declarations to each other, a unique
 * `(name, version)`, a unique label per name, one `stateVersion` and one `#[ExposesState]` allowlist
 * per name, hold over metadata alone, so they fall at construction under both regimes rather than
 * waiting for something to force every graph. `has()` and `latestVersion()` answer from the index and
 * assemble nothing.
 *
 * What lazy assembly defers is the per-graph verdict: a malformed declaration is refused when its own
 * definition is first assembled. `storm:saga:validate` is the gate that forces all of them before a
 * deployment, and it is the consuming application's pipeline that must run it.
 *
 * Versioning and pinning: a name can carry several versions at once, an evolved definition
 * co-registered as a higher version alongside the one its in-flight instances are pinned to. A new
 * instance is born under `latestVersion()`; an existing instance resolves its pinned version via
 * `get()` with an explicit version.
 *
 * @see WorkflowIndex
 * @see LazyWorkflowSource
 * @see \Storm\Saga\Console\ValidateSagaCommand
 */
final class WorkflowRegistry
{
    /** @var array<string, array<int, WorkflowDefinition>> memoized graphs, keyed by name then version */
    private array $definitions = [];

    private WorkflowIndex $index;

    /** Null under the assembled regime, where every declared pair is memoized at construction. */
    private ?LazyWorkflowSource $source = null;

    /**
     * @param  iterable<WorkflowDefinition>  $definitions
     *
     * @throws InvalidWorkflowDefinition when a version or state version is `< 1`, two definitions share
     *                                   a `(name, version)` pair, two versions of one name share a
     *                                   label, or co-registered versions disagree on `stateVersion`
     *                                   or on the `#[ExposesState]` allowlist
     */
    public function __construct(iterable $definitions = [])
    {
        $assembled = [];
        $declared = [];

        foreach ($definitions as $definition) {
            $assembled[] = $definition;
            $declared[] = WorkflowMetadata::fromDefinition($definition);
        }

        // the index judges the whole table first, so a refused declaration leaves nothing half-registered
        $this->index = new WorkflowIndex($declared);

        foreach ($assembled as $definition) {
            $this->definitions[$definition->name][$definition->version] = $definition;
        }
    }

    /**
     * Build the registry by assembling every discovered `#[Workflow]` instance at once.
     *
     * @param  iterable<object>  $workflows
     *
     * @throws ReflectionException
     * @throws InvalidWorkflowDefinition when a discovered `#[Workflow]` class is malformed or conflicts
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function fromWorkflows(iterable $workflows, WorkflowBuilder $builder): self
    {
        $definitions = [];

        foreach ($workflows as $workflow) {
            $definitions[] = $builder->build($workflow);
        }

        return new self($definitions);
    }

    /**
     * Build the registry over a compiled declaration index, assembling nothing.
     *
     * The DI factory of the lazy regime: the compiler pass hands the index it read off the
     * `#[Workflow]` classes and a locator keyed by `WorkflowMetadata::keyFor()`.
     *
     * @param  ContainerInterface  $workflows  the `(name, version)`-keyed workflow-instance locator
     *
     * @throws InvalidWorkflowDefinition when the index itself is contradictory
     */
    public static function lazy(WorkflowIndex $index, ContainerInterface $workflows, WorkflowBuilder $builder): self
    {
        $registry = new self;
        $registry->index = $index;
        $registry->source = new LazyWorkflowSource($workflows, $builder);

        return $registry;
    }

    /**
     * Is a workflow registered? With `$version` null, asks whether the name exists at all; with a
     * version, whether that specific version is registered. Assembles nothing.
     */
    public function has(string $name, ?int $version = null): bool
    {
        return $this->index->has($name, $version);
    }

    /**
     * Resolve a definition, assembling it on first use under the lazy regime. With `$version` null,
     * resolves the latest version, a new instance's birth definition; with a version, resolves that
     * pinned version for an existing instance.
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     * @throws WorkflowVersionNotFound when `$name` exists but not at `$version`, such as purged while an
     *                                 instance was still pinned to it
     * @throws InvalidWorkflowDefinition when the declaration assembled here is malformed
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function get(string $name, ?int $version = null): WorkflowDefinition
    {
        $version ??= $this->latestVersion($name);

        $memoized = $this->definitions[$name][$version] ?? null;
        if ($memoized !== null) {
            return $memoized;
        }

        // one refusal for two regimes: assembled, an unmemoized pair was never registered; lazy, the
        // index is the register, and a pair it does not hold has no service to assemble
        if ($this->source === null || ! $this->index->has($name, $version)) {
            throw WorkflowVersionNotFound::for($name, $version);
        }

        return $this->definitions[$name][$version] = $this->source->assemble($name, $version);
    }

    /**
     * The highest registered version of `$name`, the version a new instance pins at birth. Assembles
     * nothing.
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     */
    public function latestVersion(string $name): int
    {
        return $this->index->latestVersion($name);
    }

    /**
     * Every registered version of `$name`, keyed by a version; assembles the ones not yet memoized.
     *
     * @return array<int, WorkflowDefinition>
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     * @throws WorkflowVersionNotFound when a declared version has no service to assemble
     * @throws InvalidWorkflowDefinition when a declaration assembled here is malformed
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function versions(string $name): array
    {
        $resolved = [];

        foreach ($this->index->versionsOf($name) as $version) {
            $resolved[$version] = $this->get($name, $version);
        }

        return $resolved;
    }

    /**
     * Every registered definition, flattened across all names and versions; assembles the ones not yet
     * memoized, and therefore stops at the FIRST malformed declaration. The exhaustive report a
     * deployment gate needs is `storm:saga:validate`, which resolves pair by pair and collects.
     *
     * @return list<WorkflowDefinition>
     *
     * @throws WorkflowNotFound when a declared name has no registered version
     * @throws WorkflowVersionNotFound when a declared version has no service to assemble
     * @throws InvalidWorkflowDefinition when a declaration assembled here is malformed
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->index->declared() as $name => $versions) {
            foreach ($versions as $version) {
                $all[] = $this->get($name, $version);
            }
        }

        return $all;
    }

    /**
     * The registered `(name, version)` pairs, every name with its version numbers, assembling nothing.
     * What a caller iterates to resolve one pair at a time and survive a failure.
     *
     * @return array<string, non-empty-list<int>>
     */
    public function declared(): array
    {
        return $this->index->declared();
    }
}
