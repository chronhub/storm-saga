<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use DateTimeImmutable;
use Storm\Saga\Exception\InvalidRedriveBatch;

/**
 * One bounded page of a batch redrive: a workflow type, a creation window and a primary-key cursor.
 *
 * The window dates the command's CREATION, `created_at`, never its failure: no immutable first-failure
 * instant exists. `$createdBefore` is exclusive, `$createdSince` inclusive, so two adjacent windows
 * sharing a bound never examine one row twice. A resumed pass keeps the type and the window and
 * advances only `$afterId`.
 */
final readonly class RedriveBatchScope
{
    /** The ceiling of candidates one invocation may examine. */
    public const int MAX_LIMIT = 1000;

    /**
     * @throws InvalidRedriveBatch when the type is blank, the limit leaves 1..MAX_LIMIT, the cursor is negative or the window is empty
     */
    public function __construct(
        public string $workflowType,
        /** The maximum number of candidates selected, not a bound on rows scanned. */
        public int $limit,
        public DateTimeImmutable $createdBefore,
        public ?DateTimeImmutable $createdSince = null,
        /** Only ids strictly greater are selected; `0` starts from the first row. */
        public int $afterId = 0,
    ) {
        if ($workflowType === '' || trim($workflowType) !== $workflowType) {
            throw InvalidRedriveBatch::blankWorkflowType();
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw InvalidRedriveBatch::limitOutOfRange($limit, self::MAX_LIMIT);
        }

        if ($afterId < 0) {
            throw InvalidRedriveBatch::negativeCursor($afterId);
        }

        if ($createdSince !== null && $createdSince >= $createdBefore) {
            throw InvalidRedriveBatch::emptyWindow();
        }
    }
}
