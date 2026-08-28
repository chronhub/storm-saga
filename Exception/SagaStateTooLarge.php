<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Saga\Store\WorkflowInstanceRow;

/**
 * A saga instance whose JSON bags grew past what an orchestration state is meant to be. Refused at the
 * write, so the whole step rolls back rather than persisting a row the next step will have to carry.
 *
 * The cap is not a storage limit, since jsonb would take megabytes; it is a design smell caught early. An
 * orchestration state holds ids, amounts and flags, a few hundred bytes across all four bags. Something
 * orders of magnitude past that is a bag being APPENDED to on every step, a log or a growing list, and
 * the cost is recurrent rather than one-off: `update()` rewrites all four bags in one statement, so every
 * single transition pays for the whole thing again.
 *
 * @see WorkflowInstanceRow the bags and what each is for
 */
final class SagaStateTooLarge extends RuntimeException implements SagaException
{
    /**
     * @param  array<string, int>  $sizes  encoded byte size per bag, so the message names the culprit
     *                                     instead of the total
     */
    public static function forInstance(string $workflowType, string $correlationId, int $total, int $max, array $sizes): self
    {
        arsort($sizes);
        $breakdown = [];
        foreach ($sizes as $bag => $bytes) {
            $breakdown[] = sprintf('%s=%d', $bag, $bytes);
        }

        return new self(sprintf(
            'Saga "%s/%s" carries %d bytes of state, over the %d-byte cap (%s). A saga state holds ids,'
            .' amounts and flags — a bag this size is being appended to on every step, and since one'
            .' update rewrites all of them, every transition pays for it again. Keep the growing data'
            .' where it belongs: its own stream, a read model, or a reference the state points at.',
            $workflowType,
            $correlationId,
            $total,
            $max,
            implode(', ', $breakdown),
        ));
    }
}
