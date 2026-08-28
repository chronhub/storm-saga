<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Override;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A tiny array-backed PSR container for the builder tests, resolving activity FQCNs to instances.
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /**
     * @param  array<string, mixed>  $services
     */
    public function __construct(
        private array $services = [],
    ) {}

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    #[Override]
    public function get(string $id): mixed
    {
        return $this->services[$id] ?? throw new class("No service \"$id\".") extends RuntimeException implements NotFoundExceptionInterface {};
    }
}
