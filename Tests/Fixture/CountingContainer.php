<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Closure;
use Override;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * A PSR container over factories that records which ids were actually resolved.
 *
 * The seam the lazy tests assert on: a factory that never ran is a service that was never
 * instantiated, so the recorded id list is the proof of what a resolution did and did not touch.
 * Each id is built at most once, the way a service container answers.
 */
final class CountingContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $built = [];

    /** @var list<string> */
    private array $resolved = [];

    /**
     * @param  array<string, Closure(): mixed>  $factories
     */
    public function __construct(
        private readonly array $factories,
    ) {}

    #[Override]
    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    #[Override]
    public function get(string $id): mixed
    {
        if (! isset($this->factories[$id])) {
            throw new class("No service \"$id\".") extends RuntimeException implements NotFoundExceptionInterface {};
        }

        $this->resolved[] = $id;

        return $this->built[$id] ??= ($this->factories[$id])();
    }

    /**
     * The ids handed out so far, in call order, repeats included.
     *
     * @return list<string>
     */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /**
     * The ids actually instantiated, deduplicated; what a second `get()` of the same id does not grow.
     *
     * @return list<string>
     */
    public function instantiated(): array
    {
        return array_keys($this->built);
    }
}
