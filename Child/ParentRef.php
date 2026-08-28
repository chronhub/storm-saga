<?php

declare(strict_types=1);

namespace Storm\Saga\Child;

use Storm\Saga\Exception\InvalidChildIdentity;

/**
 * The declared parent of a child saga, carried in the birth context under `$parent`, the first
 * framework-owned context key, `$`-prefixed by the system-namespace convention the streams use.
 *
 * The context is written at birth and untouchable by any mover, which is exactly the property
 * parenthood needs: set once, immutable, and the schema PROJECTS it into generated columns for the
 * cascade and sweep scans; one source of truth, so the queryable columns can never drift from the
 * context that bore them.
 *
 * `depth` is the child's own depth: 1 when the parent is a root, the parent's depth plus one
 * otherwise. `rootCorrelationId` is the top of the chain, always a native correlation; it makes
 * whole-tree reads and root-first pruning a single indexed lookup instead of a recursive walk.
 */
final readonly class ParentRef
{
    public const string CONTEXT_KEY = '$parent';

    /**
     * @throws InvalidChildIdentity when a field is empty, the root lies in the child namespace,
     *                              the slot is not a valid static identifier, or `depth` does not
     *                              match the shape of the parent correlation
     */
    public function __construct(
        public string $workflowType,
        public string $correlationId,
        public string $rootCorrelationId,
        public string $slot,
        public int $depth,
    ) {
        if ($workflowType === '') {
            throw InvalidChildIdentity::malformedParentContext('parent workflow type must not be empty');
        }

        if ($correlationId === '') {
            throw InvalidChildIdentity::malformedParentContext('parent correlation id must not be empty');
        }

        if ($rootCorrelationId === '' || ChildCorrelation::isChild($rootCorrelationId)) {
            throw InvalidChildIdentity::malformedParentContext('root correlation id must be a non-empty native correlation');
        }

        if (! ChildCorrelation::isValidSlot($slot)) {
            throw InvalidChildIdentity::invalidSlot($slot);
        }

        if ($depth < 1) {
            throw InvalidChildIdentity::malformedParentContext('depth must be at least 1');
        }

        $parentIsChild = ChildCorrelation::isChild($correlationId);

        if ($depth === 1 && ($parentIsChild || $correlationId !== $rootCorrelationId)) {
            throw InvalidChildIdentity::malformedParentContext('at depth 1 the parent IS the root');
        }

        if ($depth > 1 && ! $parentIsChild) {
            throw InvalidChildIdentity::malformedParentContext('past depth 1 the parent must itself be a child');
        }
    }

    /**
     * The child correlation this parent reference mints: the only identity the gate accepts for
     * a birth that declares this parent.
     */
    public function childCorrelationId(): string
    {
        return ChildCorrelation::mint($this->correlationId, $this->slot);
    }

    /**
     * @return array{type: string, correlation: string, root: string, slot: string, depth: int}
     */
    public function toContext(): array
    {
        return [
            'type' => $this->workflowType,
            'correlation' => $this->correlationId,
            'root' => $this->rootCorrelationId,
            'slot' => $this->slot,
            'depth' => $this->depth,
        ];
    }

    /**
     * Reads the parent declaration out of a context bag: null when the key is absent, meaning a
     * root instance, and loud when the key is present but malformed, since a half-declared parent
     * must never be read as a root.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidChildIdentity when the `$parent` entry is present but not a well-formed declaration
     */
    public static function fromContext(array $context): ?self
    {
        if (! array_key_exists(self::CONTEXT_KEY, $context)) {
            return null;
        }

        $raw = $context[self::CONTEXT_KEY];

        if (! is_array($raw)) {
            throw InvalidChildIdentity::malformedParentContext('the entry must be an array');
        }

        foreach (['type', 'correlation', 'root', 'slot'] as $field) {
            if (! isset($raw[$field]) || ! is_string($raw[$field])) {
                throw InvalidChildIdentity::malformedParentContext(sprintf('"%s" must be a string', $field));
            }
        }

        if (! isset($raw['depth']) || ! is_int($raw['depth'])) {
            throw InvalidChildIdentity::malformedParentContext('"depth" must be an integer');
        }

        return new self($raw['type'], $raw['correlation'], $raw['root'], $raw['slot'], $raw['depth']);
    }
}
