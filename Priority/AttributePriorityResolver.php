<?php

declare(strict_types=1);

namespace Storm\Saga\Priority;

use ReflectionClass;
use Storm\Saga\Attributes\Prioritized;

/**
 * The default `PriorityResolver`: reads the command's #[Prioritized] level by reflection, cached per
 * class. The level is intrinsic to the command class, so it never changes within a process; the cache
 * stores a null result too, so an unmarked command is reflected once, not on every dispatch.
 */
final class AttributePriorityResolver implements PriorityResolver
{
    /** @var array<class-string, int|null> per-class cache of the #[Prioritized] level; null means no attribute */
    private array $cache = [];

    /**
     * @param  int|null  $default  the global default level for a saga-issued command; null means no priority
     * @param  array<string, int>  $perWorkflow  workflowType => its default level, overriding $default
     */
    public function __construct(
        private readonly ?int $default = null,
        private readonly array $perWorkflow = [],
    ) {}

    public function resolve(object $command, string $workflowType): ?int
    {
        return $this->levelOf($command::class)
            ?? $this->perWorkflow[$workflowType]
            ?? $this->default;
    }

    /**
     * @param  class-string  $class
     */
    private function levelOf(string $class): ?int
    {
        if (! array_key_exists($class, $this->cache)) {
            $this->cache[$class] = $this->readLevel($class);
        }

        return $this->cache[$class];
    }

    /**
     * @param  class-string  $class
     */
    private function readLevel(string $class): ?int
    {
        $attributes = new ReflectionClass($class)->getAttributes(Prioritized::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->level;
    }
}
