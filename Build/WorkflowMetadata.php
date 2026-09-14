<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use ReflectionClass;
use ReflectionException;
use Storm\Saga\Attributes\Workflow;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * The identity half of a workflow declaration: everything `WorkflowIndex` needs to enforce the
 * cross-version laws, and nothing that requires assembling a graph.
 *
 * The five values come from the class-level `#[Workflow]` and `#[ExposesState]` attributes alone, so
 * they are readable by reflection on a class that was never instantiated, and they are equally
 * derivable from an already-assembled definition. That double origin is the point: one law, stated
 * once in the index, governs a registry fed by built definitions and a registry fed by a compiled
 * declaration table.
 *
 * @see WorkflowIndex
 */
final readonly class WorkflowMetadata
{
    /**
     * @param  list<string>  $exposedStateKeys  the `#[ExposesState]` allowlist, empty when closed
     */
    public function __construct(
        public string $name,
        public int $version,
        public ?string $label,
        public int $stateVersion,
        public array $exposedStateKeys,
    ) {}

    /**
     * The assembled origin: an already-built definition carries the same five values.
     */
    public static function fromDefinition(WorkflowDefinition $definition): self
    {
        return new self(
            $definition->name,
            $definition->version,
            $definition->label,
            $definition->stateVersion,
            $definition->exposedStateKeys,
        );
    }

    /**
     * The declared origin: read the class attributes without instantiating the class.
     *
     * Reflection on the class alone, so a compiler pass can index services whose activities are not
     * resolvable at build time. The exposure allowlist goes through `DeclarationReader`, the one
     * place that judges a blank or duplicated key, so a declaration refused here is refused in the
     * same words when the graph is later assembled.
     *
     * @param  class-string  $class
     *
     * @throws InvalidWorkflowDefinition when the class carries no `#[Workflow]`, declares a blank
     *                                   name, or exposes a blank or duplicated state key
     * @throws ReflectionException
     */
    public static function fromClass(string $class): self
    {
        $reflection = new ReflectionClass($class);

        $attribute = ($reflection->getAttributes(Workflow::class)[0] ?? null)?->newInstance()
            ?? throw InvalidWorkflowDefinition::noWorkflowAttribute($class);

        if (trim($attribute->name) === '') {
            throw InvalidWorkflowDefinition::workflowNameBlank($class);
        }

        return new self(
            $attribute->name,
            $attribute->version,
            $attribute->label,
            $attribute->stateVersion,
            new DeclarationReader()->exposedStateKeys($reflection, $attribute->name),
        );
    }

    /**
     * The locator key of this declaration, the `(name, version)` pair flattened to one string.
     */
    public function key(): string
    {
        return self::keyFor($this->name, $this->version);
    }

    /**
     * The locator key a resolution asks for, built from a name and a resolved version.
     */
    public static function keyFor(string $name, int $version): string
    {
        return $name.':'.$version;
    }
}
