<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

use Storm\Clock\PointInTime;
use Storm\Saga\Store\TimerKind;

/**
 * The FINAL timer effects of one step, folded from its ordered timer operations by state key: a
 * cancel forgets every arm before it on that key, an arm after a cancel survives it, the last arm of a
 * kind wins. Folding first is what lets the store write the step in one statement: no row is touched
 * twice, a cancel excludes the kinds the same step re-arms and an arm is an upsert on its own key.
 */
final readonly class StepEffects
{
    /**
     * @param  list<array{stateKey: string, keepKinds: list<string>}>  $cancels  keys to clear, each sparing the kinds the step re-arms
     * @param  list<array{stateKey: string, kind: TimerKind, fireAt: PointInTime}>  $arms  timers to arm, one per key and kind
     */
    private function __construct(
        public array $cancels,
        public array $arms,
    ) {}

    /**
     * @param  list<array{op: TimerOp, fireAt: PointInTime}>  $resolved  the ordered operations, each with its resolved instant; a cancel's is unused
     */
    public static function fold(array $resolved): self
    {
        /** @var array<string, array{cancelled: bool, arms: array<string, array{kind: TimerKind, fireAt: PointInTime}>}> $byKey */
        $byKey = [];
        foreach ($resolved as ['op' => $op, 'fireAt' => $fireAt]) {
            $key = $op->stateKey;
            $byKey[$key] ??= ['cancelled' => false, 'arms' => []];
            $kind = match ($op->kind) {
                TimerOpKind::ArmTimeout => TimerKind::Timeout,
                TimerOpKind::ArmGlobal => TimerKind::Global,
                TimerOpKind::ArmKick => TimerKind::Kick,
                TimerOpKind::ArmSchedule => TimerKind::Schedule,
                TimerOpKind::CancelState, TimerOpKind::CancelGlobal => null,
            };
            if ($kind === null) {
                $byKey[$key] = ['cancelled' => true, 'arms' => []];

                continue;
            }
            $byKey[$key]['arms'][$kind->value] = ['kind' => $kind, 'fireAt' => $fireAt];
        }

        $cancels = [];
        $arms = [];
        foreach ($byKey as $stateKey => ['cancelled' => $cancelled, 'arms' => $armed]) {
            if ($cancelled) {
                $cancels[] = ['stateKey' => $stateKey, 'keepKinds' => array_keys($armed)];
            }
            foreach ($armed as ['kind' => $kind, 'fireAt' => $fireAt]) {
                $arms[] = ['stateKey' => $stateKey, 'kind' => $kind, 'fireAt' => $fireAt];
            }
        }

        return new self($cancels, $arms);
    }

    public function withCancel(string $stateKey): self
    {
        foreach ($this->cancels as $cancel) {
            if ($cancel['stateKey'] === $stateKey) {
                return $this;
            }
        }

        return new self([...$this->cancels, ['stateKey' => $stateKey, 'keepKinds' => []]], $this->arms);
    }

    public function isEmpty(): bool
    {
        return $this->cancels === [] && $this->arms === [];
    }
}
