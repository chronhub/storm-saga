<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Saga\Engine\EffectEvidence;

/**
 * What a redrive did, or the exact reason it refused: the sealed answer of
 * {@see WorkflowOutboxWriter::redrive()}.
 *
 * One enum rather than a boolean, because every refusal here means something different to the
 * operator standing in front of it, and "nothing happened" is the one answer that would send them
 * to edit SQL by hand. The decision itself is made by the UPDATE's own WHERE, atomically; these
 * cases only NAME what that predicate rejected.
 *
 * - `Redriven`: the row is `pending` again and the relay will publish it.
 *
 * - `NotFound`: no row carries this correlation and message id. Pruned, or a typo.
 *
 * - `NotDeadLettered`: the row exists but is not `failed`; `pending` is already in flight,
 *   `published` succeeded, `cancelled` was recalled by an aborting settle. None of the three is a
 *   command to re-send.
 *
 * - `SagaNotRunning`: the saga settled, or its row is gone. Re-sending would put an effect in flight
 *   for a saga that will never receive its outcome, an orphan the compensation logic cannot reach.
 *
 * - `StaleGeneration`: the row belongs to an EARLIER run of a reusing correlation, an artifact that
 *   must never cross into the living run.
 *
 * - `EffectUnproven`: the dead-letter carries {@see EffectEvidence::Unknown}, so nobody proved the
 *   handler's writes rolled back; the effect may be out there and re-sending may DOUBLE it. The
 *   refusal is the default because it is the safe one; `--force` owns the risk explicitly.
 *
 * - `Raced`: the row moved between the write and the diagnosis, so every guard reads as satisfied
 *   while the update changed nothing. Rare, and reported rather than dressed up as one of the
 *   refusals above: a diagnosis that invents a reason is worse than one that says "try again".
 */
enum RedriveOutcome: string
{
    case Redriven = 'redriven';

    case NotFound = 'not_found';

    case NotDeadLettered = 'not_dead_lettered';

    case SagaNotRunning = 'saga_not_running';

    case StaleGeneration = 'stale_generation';

    case EffectUnproven = 'effect_unproven';

    case Raced = 'raced';

    /**
     * Whether the command is back in flight; every other case changed nothing.
     */
    public function applied(): bool
    {
        return $this === self::Redriven;
    }
}
