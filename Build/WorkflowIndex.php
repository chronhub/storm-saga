<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Exception\WorkflowNotFound;

/**
 * The declaration table of every registered workflow, and the sole keeper of the laws that hold
 * ACROSS declarations.
 *
 * Four of those laws are global by nature: they compare one declaration against the others, so no
 * single assembled graph can prove them and a registry that assembles on demand would leave them
 * latent until something forced every graph. Stated here, over metadata alone, they fall at
 * construction whatever feeds the index:
 *
 * - A `(name, version)` pair names exactly one declaration
 * - A version `label`, when set, is unique among the versions of its name
 * - Co-registered versions of one name agree on `stateVersion`
 * - Co-registered versions of one name agree on the `#[ExposesState]` allowlist
 *
 * The last two are per NAME rather than per version because co-registered versions share their
 * activities, so they share one data contract and one security declaration; a disagreement is a
 * declaration bug, never a preference.
 *
 * The two per-declaration floors, a version and a state version of at least 1, are checked here too
 * so one pass answers for the whole table.
 *
 * @see WorkflowMetadata
 * @see WorkflowRegistry
 */
final class WorkflowIndex
{
    /** @var array<string, non-empty-array<int, WorkflowMetadata>> keyed by name, then by version */
    private array $declarations = [];

    /**
     * @param  iterable<WorkflowMetadata>  $declarations
     *
     * @throws InvalidWorkflowDefinition when a version or state version is `< 1`, two declarations
     *                                   share a `(name, version)` pair, two versions of one name
     *                                   share a label, or co-registered versions disagree on
     *                                   `stateVersion` or on the `#[ExposesState]` allowlist
     */
    public function __construct(iterable $declarations = [])
    {
        /** @var array<string, array<string, true>> $labels keyed by name, then by seen label */
        $labels = [];
        /** @var array<string, int> $stateVersions the per-name data contract, first-seen wins the comparison */
        $stateVersions = [];
        /** @var array<string, list<string>> $exposures the per-name exposure allowlist, same law */
        $exposures = [];

        foreach ($declarations as $declaration) {
            $name = $declaration->name;
            $version = $declaration->version;

            if ($version < 1) {
                throw InvalidWorkflowDefinition::versionBelowOne($name, $version);
            }
            if ($declaration->stateVersion < 1) {
                throw InvalidWorkflowDefinition::stateVersionBelowOne($name, $declaration->stateVersion);
            }
            if (isset($this->declarations[$name][$version])) {
                throw InvalidWorkflowDefinition::duplicateVersion($name, $version);
            }
            if ($declaration->label !== null && isset($labels[$name][$declaration->label])) {
                throw InvalidWorkflowDefinition::duplicateLabel($name, $declaration->label);
            }
            if (isset($stateVersions[$name]) && $stateVersions[$name] !== $declaration->stateVersion) {
                throw InvalidWorkflowDefinition::stateVersionDisagreement($name, $stateVersions[$name], $version, $declaration->stateVersion);
            }
            $stateVersions[$name] = $declaration->stateVersion;
            if (isset($exposures[$name]) && $exposures[$name] !== $declaration->exposedStateKeys) {
                throw InvalidWorkflowDefinition::exposedStateDisagreement($name, $version);
            }
            $exposures[$name] = $declaration->exposedStateKeys;

            $this->declarations[$name][$version] = $declaration;
            if ($declaration->label !== null) {
                // @infection-ignore-all; equivalent: the duplicate-label guard above reads this set by isset()
                // alone, which is value-agnostic; only the key's presence matters
                $labels[$name][$declaration->label] = true;
            }
        }
    }

    /**
     * Is a workflow declared? With `$version` null, asks whether the name exists at all; with a
     * version, whether that specific version is declared.
     */
    public function has(string $name, ?int $version = null): bool
    {
        if ($version === null) {
            return isset($this->declarations[$name]);
        }

        return isset($this->declarations[$name][$version]);
    }

    /**
     * The highest declared version of `$name`, the version a new instance pins at birth.
     *
     * @throws WorkflowNotFound when no version of `$name` is declared
     */
    public function latestVersion(string $name): int
    {
        $byVersion = $this->declarations[$name] ?? throw WorkflowNotFound::named($name);

        return max(array_keys($byVersion));
    }

    /**
     * Every declared version number of `$name`, in declaration order.
     *
     * @return non-empty-list<int>
     *
     * @throws WorkflowNotFound when no version of `$name` is declared
     */
    public function versionsOf(string $name): array
    {
        $byVersion = $this->declarations[$name] ?? throw WorkflowNotFound::named($name);

        return array_keys($byVersion);
    }

    /**
     * The whole table flattened to what a caller can iterate without assembling anything: every
     * name with its declared version numbers, in declaration order.
     *
     * @return array<string, non-empty-list<int>>
     */
    public function declared(): array
    {
        return array_map(array_keys(...), $this->declarations);
    }

    /**
     * The metadata of one declaration, or null when the pair is not declared.
     */
    public function metadata(string $name, int $version): ?WorkflowMetadata
    {
        return $this->declarations[$name][$version] ?? null;
    }
}
