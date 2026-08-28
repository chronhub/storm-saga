<?php

declare(strict_types=1);

namespace Storm\Saga\Testing\InMemory;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * The activity locator of a standalone runtime: the given activity instances keyed by class, the
 * shape the real `WorkflowBuilder` resolves activities from, so a workflow built here verifies the
 * same attribute validity and activity resolution as one built by the container's tagged locator.
 */
final readonly class ActivityLocator implements ContainerInterface
{
    /** @var array<class-string, object> */
    private array $activities;

    /**
     * @param  list<object>  $activities
     */
    public function __construct(array $activities)
    {
        $keyed = [];
        foreach ($activities as $activity) {
            $keyed[$activity::class] = $activity;
        }
        $this->activities = $keyed;
    }

    public function get(string $id): object
    {
        return $this->activities[$id] ?? throw new class(sprintf('No activity "%s" was given to the runtime.', $id)) extends RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->activities[$id]);
    }
}
