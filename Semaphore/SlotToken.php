<?php

declare(strict_types=1);

namespace Storm\Saga\Semaphore;

use Storm\Saga\Child\ChildCorrelation;

/**
 * The semaphore's slot identity: a token IS the waiter's identity, `workflowType` and `correlationId`
 * joined on the same control delimiter as {@see ChildCorrelation}. One saga holds at most ONE slot per
 * resource by construction: a redelivered acquire finds its own token and gets the same answer back,
 * and a saga cannot stack grants against itself, which is what makes the deadlock shape "I wait on the
 * semaphore I already hold" structurally discouraged rather than a caller promise.
 */
final readonly class SlotToken
{
    /** The waiter-identity join character: a control byte no printable id contains. */
    public const string DELIMITER = "\x1f";

    public static function of(string $waiterType, string $waiterCorrelation): string
    {
        return $waiterType.self::DELIMITER.$waiterCorrelation;
    }
}
