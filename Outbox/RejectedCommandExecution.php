<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use RuntimeException;

/**
 * A permanent execution refusal whose effects remain unknown.
 *
 * Stops relay retries without claiming that no handler ran or that compensation is safe.
 */
final class RejectedCommandExecution extends RuntimeException {}
