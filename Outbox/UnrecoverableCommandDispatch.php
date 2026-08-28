<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Throwable;

/**
 * Marker for a command-dispatch failure that retrying can never fix: no handler is wired for the
 * command, or the payload is one the validation middleware will always reject. The opposite of a
 * transient outage such as a broker down or a deadlock.
 *
 * By default `SagaOutboxRelay` treats every `publish()` throwable as transient, retrying with back-off
 * and dead-lettering only after `maxAttempts`, the safe assumption. A publisher that knows a command is
 * hopeless throws an exception implementing this interface, and the relay dead-letters it immediately:
 * this both stops it burning the retry budget and lets the post-commit `failIssuedEffect`, the saga's
 * settle and compensation, run sooner. Mirror of the event outbox's `UnrecoverablePublishFailure`.
 *
 * A marker with no members: the relay matches it via `instanceof`, so any exception can opt in without a
 * forced base class. For the common case throw the shipped `UnroutableCommand`.
 *
 * @see SagaOutboxRelay
 */
interface UnrecoverableCommandDispatch extends Throwable {}
