<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use LogicException;

/**
 * An activity emission lacks a durable wait or confirmation contract. Raised outside the
 * activity failure boundary so retries and fallbacks cannot conceal the authoring error.
 */
final class UnsafeActivityCommands extends LogicException implements SagaException
{
    public static function async(string $stateKey, string $workflowType): self
    {
        return new self(sprintf(
            'Activity state "%s" in workflow "%s" returned async commands. Emit with success and enter an explicit wait instead.',
            $stateKey,
            $workflowType,
        ));
    }

    public static function unconfirmed(string $stateKey, string $workflowType): self
    {
        return new self(sprintf(
            'Compensable activity state "%s" in workflow "%s" emitted commands without compensationConfirmedBy.',
            $stateKey,
            $workflowType,
        ));
    }

    public static function withoutWait(string $stateKey, string $workflowType): self
    {
        return new self(sprintf(
            'Compensable activity state "%s" in workflow "%s" emitted commands without selecting an immediate WaitState.',
            $stateKey,
            $workflowType,
        ));
    }
}
