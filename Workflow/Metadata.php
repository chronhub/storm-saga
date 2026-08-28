<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Exception\InvalidMetadata;
use Storm\Saga\Store\WorkflowId;

/**
 * Run context handed to an `Activity`: which workflow and instance via `workflowType` and
 * `correlationId`, the optional parent `causationId` for tracing, and a free `enrichedContext` bag such as
 * actor or origin.
 *
 * The ids obey {@see WorkflowId}'s rules, borrowed rather than restated: the saga columns are `text`,
 * so there is no "storage column width" to bound them to; what actually bites is the b-tree index
 * row cap and the text path's silent NUL truncation, both of which WorkflowId measures and names.
 * This class adds `causationId`, which WorkflowId does not carry, and holds it to the same rules;
 * the checks stay here so an Activity's context is verified even when it was assembled by hand
 * rather than derived from an identity.
 *
 * @see Activity
 */
final readonly class Metadata
{
    /**
     * @param  array<string, mixed>  $enrichedContext
     *
     * @throws InvalidMetadata when an id is blank, over the byte cap, or carries a character the text
     *                         path cannot round-trip
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public ?string $causationId = null,
        public array $enrichedContext = [],
    ) {
        self::guardRequired('workflowType', $workflowType);
        self::guardRequired('correlationId', $correlationId);

        if ($causationId !== null) {
            self::guardRequired('causationId', $causationId);
        }
    }

    /**
     * @throws InvalidMetadata when `$value` breaks one of the identity rules; translated to this class's
     *                         own exception, since a bad activity context is the caller's bug either way
     */
    private static function guardRequired(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw InvalidMetadata::empty($field);
        }

        if (strlen($value) > WorkflowId::MAX_BYTES) {
            throw InvalidMetadata::tooLong($field, WorkflowId::MAX_BYTES, strlen($value));
        }

        if (preg_match(WorkflowId::SHAPE_REGEX, $value) !== 1) {
            throw InvalidMetadata::malformed($field);
        }
    }
}
