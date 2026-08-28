<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

/**
 * A plain event object for wait-matching tests. The pure engine matches any object; it doesn't
 * require a framework event type.
 */
final class SampleEvent {}
