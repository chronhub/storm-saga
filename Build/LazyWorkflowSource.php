<?php

declare(strict_types=1);

namespace Storm\Saga\Build;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\WorkflowDefinition;

/**
 * Assembles ONE workflow graph on demand, from a locator keyed by the `(name, version)` pair.
 *
 * The locator is the whole point: asking it for one key instantiates that workflow service and,
 * through the builder, only the activities that workflow declares. Every other declared workflow and
 * every activity it alone uses stay unbuilt, which is what makes a request pay for the workflow it
 * touches instead of the catalogue.
 *
 * @see WorkflowRegistry the memoizing caller
 * @see WorkflowMetadata::keyFor() the key shape both sides agree on
 */
final readonly class LazyWorkflowSource
{
    /**
     * @param  ContainerInterface  $workflows  keyed by `WorkflowMetadata::keyFor()`, each entry the
     *                                         `#[Workflow]` instance to reflect
     */
    public function __construct(
        private ContainerInterface $workflows,
        private WorkflowBuilder $builder,
    ) {}

    /**
     * @throws InvalidWorkflowDefinition when the declaration is malformed or an activity is unresolvable
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function assemble(string $name, int $version): WorkflowDefinition
    {
        return $this->builder->build($this->workflows->get(WorkflowMetadata::keyFor($name, $version)));
    }
}
