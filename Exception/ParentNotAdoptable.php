<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use RuntimeException;
use Storm\Saga\Child\ChildSpawner;
use Storm\Saga\Store\WorkflowFamilies;
use Storm\Saga\Store\WorkflowStatus;

/**
 * A birth declared a parent the parent's own row does not support: gone, terminal, or a different
 * workflow type than the declaration claims. Raised INSIDE the birth's fenced transaction, after the
 * parent row was read under a shared lock, so the whole step rolls back and no child is created.
 *
 * The proof lives there, and not only in the spawner's pre-check, for two reasons. Under the lock it
 * is ORDERED against the parent's settle, which closes the orphan window a lock-free check-then-act
 * leaves open. And it applies to every birth that declares a parent, including one an application
 * hand-builds, so a forged declaration cannot attach a child to a saga that never asked for it; the
 * gate proves identity, this proves adoption.
 *
 * Scope: this family carries the EXISTENCE refusals, the ones that can be a RACE resolving, which
 * is why the spawner routes them to its announced skip. The CONSENT refusal, a slot the parent's
 * pinned definition never declared or declared for another child type, is deterministic and
 * therefore a different exception on a different channel: `ChildSpawnRefused`, dead-lettered,
 * never skipped.
 *
 * Disposition is the caller's, and the two callers differ on purpose: `ChildSpawner` catches it and
 * turns it into its ANNOUNCED skip, since losing the cancel-versus-spawn race is normal and must not
 * manufacture dead-letter noise; a direct `start()` lets it fly, since a hand-built parent that does
 * not hold up is a defect, not a race.
 *
 * @see ChildSpawner
 * @see WorkflowFamilies::loadAdoptableParent()
 */
final class ParentNotAdoptable extends RuntimeException implements SagaException
{
    /**
     * The announceable reason, the vocabulary the spawner's skip already speaks.
     */
    public readonly string $reason;

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    public static function missing(string $childCorrelationId, string $parentCorrelationId): self
    {
        return new self(
            sprintf(
                'Birth of "%s" declares parent "%s", which has no row — the parent settled and was pruned, or never existed.',
                $childCorrelationId,
                $parentCorrelationId,
            ),
            'parent-missing',
        );
    }

    public static function terminal(string $childCorrelationId, string $parentCorrelationId, WorkflowStatus $status): self
    {
        return new self(
            sprintf(
                'Birth of "%s" declares parent "%s", which is %s — a terminal parent adopts nothing; the child would be born orphan.',
                $childCorrelationId,
                $parentCorrelationId,
                $status->value,
            ),
            'parent-terminal',
        );
    }

    public static function typeMismatch(string $childCorrelationId, string $parentCorrelationId, string $declared, string $actual): self
    {
        return new self(
            sprintf(
                'Birth of "%s" declares parent "%s" as "%s", but that correlation belongs to "%s".',
                $childCorrelationId,
                $parentCorrelationId,
                $declared,
                $actual,
            ),
            'parent-mismatch',
        );
    }
}
