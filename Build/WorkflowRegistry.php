<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Exception\WorkflowVersionNotFound;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Lookup of the built workflow definitions by `(name, version)`. The definitions are discovered at boot:
 * the `#[Workflow]` classes are tagged at compile time, then each is built by `WorkflowBuilder`, with no
 * runtime filesystem scan and no cache file.
 *
 * Versioning and pinning: a name can carry several versions at once, an evolved definition co-registered
 * as a higher version alongside the one its in-flight instances are pinned to. A new instance is born
 * under `latestVersion()`; an existing instance resolves its pinned version via `get()` with an explicit
 * version. The `(name, version)` pair is unique, and a version `label` when set is unique per name; both
 * are enforced at construction.
 */
final class WorkflowRegistry
{
    /** @var array<string, non-empty-array<int, WorkflowDefinition>> keyed by name, then by version */
    private array $definitions = [];

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
        /** @var array<string, array<string, true>> $labels keyed by name, then by seen label */
        $labels = [];
        /** @var array<string, int> $stateVersions the per-name data contract, first-seen wins the comparison */
        $stateVersions = [];
        /** @var array<string, list<string>> $exposures the per-name exposure allowlist, same law */
        $exposures = [];

        foreach ($definitions as $definition) {
            $name = $definition->name;
            $version = $definition->version;

            if ($version < 1) {
                throw InvalidWorkflowDefinition::versionBelowOne($name, $version);
            }
            if ($definition->stateVersion < 1) {
                throw InvalidWorkflowDefinition::stateVersionBelowOne($name, $definition->stateVersion);
            }
            if (isset($this->definitions[$name][$version])) {
                throw InvalidWorkflowDefinition::duplicateVersion($name, $version);
            }
            if ($definition->label !== null && isset($labels[$name][$definition->label])) {
                throw InvalidWorkflowDefinition::duplicateLabel($name, $definition->label);
            }
            // the data contract is per NAME: co-registered versions share their activities, so a
            // disagreement is a declaration bug, the same agreement law as the class-level #[Prioritized]
            if (isset($stateVersions[$name]) && $stateVersions[$name] !== $definition->stateVersion) {
                throw InvalidWorkflowDefinition::stateVersionDisagreement($name, $stateVersions[$name], $version, $definition->stateVersion);
            }
            $stateVersions[$name] = $definition->stateVersion;
            // same law for the exposure allowlist: a SECURITY declaration cannot depend on which
            // co-registered version happens to serve the read
            if (isset($exposures[$name]) && $exposures[$name] !== $definition->exposedStateKeys) {
                throw InvalidWorkflowDefinition::exposedStateDisagreement($name, $version);
            }
            $exposures[$name] = $definition->exposedStateKeys;

            $this->definitions[$name][$version] = $definition;
            if ($definition->label !== null) {
                // @infection-ignore-all; equivalent: the dup-detection above reads this only via isset(), which
                // is value-agnostic, since true vs. false both register the key as "seen"; only the key's presence matters
                $labels[$name][$definition->label] = true;
            }
        }
    }

    /**
     * Build the registry from the discovered `#[Workflow]` instances, each reflected into a definition by
     * the `WorkflowBuilder`. The DI factory: the bundle hands the tagged workflow services.
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
     * Is a workflow registered? With `$version` null, asks whether the name exists at all; with a
     * version, whether that specific version is registered.
     */
    public function has(string $name, ?int $version = null): bool
    {
        if ($version === null) {
            return isset($this->definitions[$name]);
        }

        return isset($this->definitions[$name][$version]);
    }

    /**
     * Resolve a definition. With `$version` null, resolves the latest version, a new instance's birth
     * definition; with a version, resolves that pinned version for an existing instance.
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     * @throws WorkflowVersionNotFound when `$name` exists but not at `$version`, such as purged while an
     *                                 instance was still pinned to it
     */
    public function get(string $name, ?int $version = null): WorkflowDefinition
    {
        if ($version === null) {
            return $this->definitions[$name][$this->latestVersion($name)];
        }

        return $this->definitions[$name][$version] ?? throw WorkflowVersionNotFound::for($name, $version);
    }

    /**
     * The highest registered version of `$name`, the version a new instance pins at birth.
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     */
    public function latestVersion(string $name): int
    {
        $byVersion = $this->definitions[$name] ?? throw WorkflowNotFound::named($name);

        return max(array_keys($byVersion));
    }

    /**
     * Every registered version of `$name`, keyed by a version.
     *
     * @return array<int, WorkflowDefinition>
     *
     * @throws WorkflowNotFound when no version of `$name` is registered
     */
    public function versions(string $name): array
    {
        return $this->definitions[$name] ?? throw WorkflowNotFound::named($name);
    }

    /**
     * Every registered definition, flattened across all names and versions.
     *
     * @return list<WorkflowDefinition>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->definitions as $byVersion) {
            foreach ($byVersion as $definition) {
                $all[] = $definition;
            }
        }

        return $all;
    }
}
