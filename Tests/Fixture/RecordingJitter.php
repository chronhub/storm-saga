<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

use Random\RandomException;
use Storm\Contracts\Random\Jitter;

/**
 * A jitter that keeps every range it is asked for and answers one fixed end of it, or fails as a
 * missing source of randomness would, so a backoff's bounds and its use of the draw are asserted
 * exactly instead of sampled.
 */
final class RecordingJitter implements Jitter
{
    /** @var list<array{int, int}> */
    public array $asked = [];

    private function __construct(private readonly string $answer) {}

    public static function lowest(): self
    {
        return new self('lowest');
    }

    public static function highest(): self
    {
        return new self('highest');
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public function between(int $min, int $max): int
    {
        $this->asked[] = [$min, $max];

        return match ($this->answer) {
            'lowest' => $min,
            'highest' => $max,
            default => throw new RandomException('no source of randomness'),
        };
    }
}
