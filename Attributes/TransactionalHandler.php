<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;
use Storm\Saga\Engine\EffectEvidence;

/**
 * The consumer's signature on a contract the engine cannot verify: this handler commits or rolls back
 * as ONE unit, so if it threw, nothing of its effect survived.
 *
 * Without it, a command that dead-letters after reaching a handler leaves the engine with
 * {@see EffectEvidence::Unknown}: it escalates the saga rather than compensating, because rolling back
 * around an effect that did land is how money gets created. With it, that same failure reads as
 * `Uncommitted` and the automatic settle runs.
 *
 * Declare it only if all three hold, since the engine takes the word for proof:
 *
 * - Every write the handler performs is inside one transaction it owns, including the writes of
 *   anything it calls;
 * - It publishes no message and calls no external service whose effect outlives a rollback; an HTTP
 *   call that succeeded before the throw is exactly the effect this contract denies;
 * - Nothing downstream of it in the middleware stack can commit after it returns.
 *
 * A handler that cannot promise that has nothing to fix: the default is already the safe reading, and
 * the saga it leaves parked is visible to the cleanup sweep and to `storm:saga:inspect`.
 *
 * Compiled at container build into the grants the failure listener reads, keyed by handled message
 * class: at the failure site only the message is known, never which handler object ran.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TransactionalHandler {}
