<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Priority;

use Storm\Saga\Priority\Priority;

/**
 * Test fixture, an app-style priority enum: it brings its OWN vocabulary and cardinality, storm only sees
 * `level()`. Stands in for a real consumer's `enum Lane: int implements Priority`.
 */
enum TestLane: int implements Priority
{
    case Capture = 40;
    case Hold = 30;

    public function level(): int
    {
        return $this->value;
    }
}
