<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * One entry in a saga's rollback log: a completed compensatable step and what became of its undo, an
 * auditable row whose `confirmed` records whether the step's effect was confirmed, that is whether the
 * success event named by `#[Compensate(confirmedBy:)]` was delivered, and whose `degraded` records that
 * the step's success came from a fallback rather than the primary activity, so its real effect is
 * uncertain. Both let a rollback distinguish intent from confirmed effect and skip what it cannot safely
 * undo.
 *
 * `arm` is the race identity: a race logs one entry PER ARM under one state key, the winner joining
 * confirmed at the victory and each loser joining already settled by its disposition. `key()` is the
 * rollback's resolution key, `(step, arm)`, because the bare step stopped being unique the day two
 * arms could share it.
 */
final readonly class CompensationRecord
{
    public function __construct(
        public string $step,
        public CompensationStatus $status,
        public bool $confirmed = false,
        public bool $degraded = false,
        public ?string $reason = null,
        public ?string $at = null,
        /** The race arm this entry belongs to; null for the ordinary one-entry-per-state case. */
        public ?string $arm = null,
    ) {}

    /**
     * Log a freshly completed compensatable step during the forward run: `Pending`, not yet confirmed.
     * `$degraded` is set when the step was salvaged by a fallback, so its real effect is uncertain.
     */
    public static function pending(string $step, ?string $at = null, bool $degraded = false): self
    {
        return new self($step, CompensationStatus::Pending, false, $degraded, null, $at);
    }

    /**
     * Log one race arm's entry at the victory: the WINNER joins confirmed, its outcome having just
     * arrived, so a later rollback may undo the kept effect through its arm; a LOSER joins already
     * settled by the disposition that handled it, recalled or compensated on the spot.
     */
    public static function forArm(string $step, string $arm, CompensationStatus $status, bool $confirmed, ?string $reason = null, ?string $at = null): self
    {
        return new self($step, $status, $confirmed, false, $reason, $at, $arm);
    }

    /**
     * The log key at rollback time: `(step, arm)`, since a race logs one entry PER ARM under one
     * state key; resolving by the bare step would let siblings overwrite each other.
     */
    public function key(): string
    {
        return $this->arm === null ? $this->step : $this->step.'#'.$this->arm;
    }

    /**
     * Mark the step's effect confirmed, its `confirmedBy` success event having been delivered.
     */
    public function confirm(?string $at = null): self
    {
        return clone ($this, ['confirmed' => true, 'at' => $at ?? $this->at]);
    }

    /**
     * Resolve the entry at rollback time to its terminal status, reason and timestamp.
     */
    public function settle(CompensationStatus $status, ?string $reason = null, ?string $at = null): self
    {
        return clone ($this, ['status' => $status, 'reason' => $reason, 'at' => $at ?? $this->at]);
    }

    /**
     * @return array{step: string, status: string, confirmed: bool, degraded: bool, reason: string|null, at: string|null, arm?: string}
     */
    public function toArray(): array
    {
        $row = [
            'step' => $this->step,
            'status' => $this->status->value,
            'confirmed' => $this->confirmed,
            'degraded' => $this->degraded,
            'reason' => $this->reason,
            'at' => $this->at,
        ];
        if ($this->arm !== null) {
            // written only when set: the ordinary entry's persisted shape stays byte-identical
            $row['arm'] = $this->arm;
        }

        return $row;
    }

    /**
     * @param array{
     *     step: string,
     *     status: string,
     *     confirmed?: bool,
     *     degraded?: bool,
     *     reason?: string|null,
     *     at?: string|null,
     *     arm?: string|null,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        $step = $data['step'];
        $status = $data['status'];
        $confirmed = $data['confirmed'] ?? false;
        $degraded = $data['degraded'] ?? false;
        $reason = $data['reason'] ?? null;
        $at = $data['at'] ?? null;
        $arm = $data['arm'] ?? null; // absent on every pre-race row: the lenient read IS the migration

        return new self($step, CompensationStatus::from($status), $confirmed, $degraded, $reason, $at, $arm);
    }
}
