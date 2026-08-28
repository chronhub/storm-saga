<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Attributes\TransactionalHandler;

/**
 * What is actually KNOWN about a dead-lettered command's effect, the settle's second input beside the
 * pairing. A dead-letter is not by itself proof that the handler transaction rolled back: of the four
 * paths that dead-letter a saga-issued command, two prove the effect never committed and two cannot.
 *
 * `Uncommitted`: no effect can have been committed, either because the command never reached a handler
 * at all, the relay failing to decode it or dispatch finding no handler, both proven by the engine and
 * asked of nobody, or because the handler that ran signed {@see TransactionalHandler}, declaring that
 * it commits or rolls back as one. Compensating around it is safe.
 *
 * `Unknown`: nobody proved anything. The retry budget ran out on a publish that, under a sync
 * transport, IS the handler; or the consumer's own retries were exhausted after it had run. The
 * effect may well have landed, so the engine escalates instead of compensating: the saga stays alive
 * and visible, where the cleanup sweep and liveness find it, rather than being rolled back around an
 * effect that is out there.
 *
 * Two cases and not three: "never delivered" and "declared transactional" differ in who vouches for
 * them, not in what the engine may do about it. The distinction that survives is the one that changes
 * a decision.
 *
 * Durable, carried on the outbox row beside the provenance, because the cleanup's reconcile re-derives
 * a lost settle from storage hours later; without the stamp it would have to assume the worst on a
 * command that was in fact proven safe.
 */
enum EffectEvidence: string
{
    case Uncommitted = 'uncommitted';

    case Unknown = 'unknown';
}
