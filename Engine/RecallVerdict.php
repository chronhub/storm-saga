<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Saga\Workflow\CompensationRecord;

/**
 * The recall's proof a rollback runs on: the logged entries whose forward commands never left, each
 * having issued at least one and none ever claimed by a relay. Such an entry has no effect to undo,
 * so the rollback settles it `Skipped`, `recalled: never dispatched`, without running its undo.
 *
 * A value, so the `Compensator` stays pure: `RecallJudge` reads the outbox under the step's fence,
 * and the verdict carries only what it found.
 */
final readonly class RecallVerdict
{
    /**
     * @param  array<string, CompensationRecord>  $recalled  each recalled entry by its log key
     */
    private function __construct(
        private array $recalled,
    ) {}

    /**
     * The verdict that proves nothing: every entry rolls back on its own eligibility.
     */
    public static function none(): self
    {
        return new self([]);
    }

    public static function of(CompensationRecord ...$entries): self
    {
        $recalled = [];
        foreach ($entries as $entry) {
            $recalled[$entry->key()] = $entry;
        }

        return new self($recalled);
    }

    public function recalls(CompensationRecord $entry): bool
    {
        return isset($this->recalled[$entry->key()]);
    }
}
