<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;

/**
 * A correlation is claimed once, for good. `workflow_correlations` remembers every correlation a saga
 * was ever born under, so a business key whose run has ENDED, and whose instance the terminal prune
 * has since deleted, cannot start a second one. Without that memory the second run would be
 * indistinguishable from the first, and an artifact of the first still in flight, a redelivered
 * outcome or a dead-lettered command, would reach it.
 *
 * The sibling refusal, {@see CorrelationAlreadyOwned}, means the opposite situation: another saga
 * holds this correlation RIGHT NOW. This one means the correlation had its run and spent it. Both are
 * deterministic; retrying cannot fix either. The fix is a fresh correlation, minted per run rather
 * than per business entity.
 */
final class CorrelationAlreadyConsumed extends RuntimeException implements SagaException
{
    public static function by(string $workflowType, string $correlationId): self
    {
        return new self(sprintf(
            'Correlation "%s" has already run a saga to completion — a correlation is claimed once and'
            .' never reused, so workflow "%s" cannot start under it. Mint a correlation per RUN, not per'
            .' business entity, when the same entity can legitimately be orchestrated more than once.',
            $correlationId,
            $workflowType,
        ));
    }
}
