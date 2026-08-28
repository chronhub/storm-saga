<?php

declare(strict_types=1);

namespace Storm\Saga\Store;

use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\InvalidWorkflowId;

/**
 * Identifies one saga instance: its `workflowType`, the `#[Workflow]` name, and the business
 * `correlationId`. The composite primary key of `workflow_instances`.
 *
 * Validated here because this is the frontier every WRITE crosses: the timer store, the outbox and
 * the instance store all take a `WorkflowId`, and a birth row is built from one. `Metadata`'s own
 * bound cannot stand in for it, since `Metadata` is built when an ACTIVITY runs, so a saga that
 * started and rested straight on a wait, a schedule or a final state would meet no check at all.
 *
 * Two rules, each protecting a failure the engine could not otherwise see:
 *
 * - A byte cap, because the id lands in indexed columns, `workflow_instances_correlation_uq` and
 *   the timers' four-column unique, where a PostgreSQL b-tree row caps out at 2 704 bytes,
 *   measured. Past it the INSERT fails deep inside a step with a message about index row size, which
 *   names neither the saga nor the offending value. The cap is in BYTES, not characters, because
 *   that is what the index counts; two ids of 512 bytes plus the short declared state key and kind
 *   stay well under the limit even multi-byte.
 *
 * - No control or format characters, because the DBAL/PostgreSQL text path TRUNCATES silently at a
 *   NUL: `order-a\0b` and `order-a\0c` both store as `order-a`, measured. For a saga that is not
 *   cosmetic: the correlation is UNIQUE and the outcome router resolves an instance from it alone,
 *   so two distinct correlations would collapse onto one row and one of them would start answering
 *   for the other. The same shape of cross-talk the correlation registry closes between generations.
 *
 * The one control character allowed is {@see ChildCorrelation::DELIMITER}, the reserved `\x1f` a
 * child's minted correlation is built with: it is structure this module writes itself, not input.
 */
final readonly class WorkflowId
{
    /**
     * Byte budget per id, mirroring `StreamName::MAX_BYTES` and for the same reason. Generous enough
     * for a UUID, a multi-segment slug or a child's parent-plus-slot mint, tight enough to catch a
     * runaway id at the frontier instead of in the index.
     */
    public const int MAX_BYTES = 512;

    /** Anything but a control or format character, save the reserved child delimiter. */
    public const string SHAPE_REGEX = '/^(?:[^\p{C}]|\x{1F})+$/u';

    /**
     * @throws InvalidWorkflowId when an id is blank, over the byte cap, or carries a character the
     *                           text path cannot round-trip
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
    ) {
        self::guard('workflowType', $workflowType);
        self::guard('correlationId', $correlationId);
    }

    /**
     * Private on purpose: the rules apply to an identity, and an identity is this object. A caller with
     * a loose string wanting them checked should build the `WorkflowId`, which is the point.
     *
     * @throws InvalidWorkflowId when `$value` breaks one of the two rules
     */
    private static function guard(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw InvalidWorkflowId::empty($field);
        }

        if (strlen($value) > self::MAX_BYTES) {
            throw InvalidWorkflowId::tooLong($field, self::MAX_BYTES, strlen($value));
        }

        if (preg_match(self::SHAPE_REGEX, $value) !== 1) {
            throw InvalidWorkflowId::malformed($field);
        }
    }
}
