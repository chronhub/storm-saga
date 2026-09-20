<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Saga\Engine\StepEffects;
use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Store\TimerKind;

final class StepEffectsTest extends TestCase
{
    #[Test]
    public function a_cancel_then_an_arm_on_one_key_folds_to_a_cancel_sparing_the_armed_kind(): void
    {
        $at = FrozenClock::at('2026-09-16T12:00:00.000000+00:00')->now();

        $effects = StepEffects::fold([
            ['op' => TimerOp::cancelState('await'), 'fireAt' => $at],
            ['op' => TimerOp::armTimeout('await', 300), 'fireAt' => $at],
        ]);

        self::assertSame([['stateKey' => 'await', 'keepKinds' => ['timeout']]], $effects->cancels);
        self::assertSame([['stateKey' => 'await', 'kind' => TimerKind::Timeout, 'fireAt' => $at]], $effects->arms);
        self::assertFalse($effects->isEmpty());
    }

    #[Test]
    public function an_arm_then_a_cancel_on_one_key_folds_to_the_cancel_alone(): void
    {
        $at = FrozenClock::at('2026-09-16T12:00:00.000000+00:00')->now();

        $effects = StepEffects::fold([
            ['op' => TimerOp::armKick('step', 100), 'fireAt' => $at],
            ['op' => TimerOp::cancelState('step'), 'fireAt' => $at],
        ]);

        self::assertSame([['stateKey' => 'step', 'keepKinds' => []]], $effects->cancels);
        self::assertSame([], $effects->arms);
    }

    #[Test]
    public function the_last_arm_of_a_kind_wins_and_kinds_stay_apart(): void
    {
        $first = FrozenClock::at('2026-09-16T12:00:00.000000+00:00')->now();
        $last = FrozenClock::at('2026-09-16T12:10:00.000000+00:00')->now();

        $effects = StepEffects::fold([
            ['op' => TimerOp::armTimeout('await', 300), 'fireAt' => $first],
            ['op' => TimerOp::armGlobal(3600), 'fireAt' => $first],
            ['op' => TimerOp::armTimeout('await', 600), 'fireAt' => $last],
        ]);

        self::assertSame([], $effects->cancels);
        self::assertCount(2, $effects->arms);
        self::assertSame($last, $effects->arms[0]['fireAt']);
        self::assertSame(TimerKind::Global, $effects->arms[1]['kind']);
    }

    #[Test]
    public function with_cancel_adds_a_key_once_and_leaves_an_empty_fold_empty_otherwise(): void
    {
        $empty = StepEffects::fold([]);
        self::assertTrue($empty->isEmpty());

        $once = $empty->withCancel('__global__')->withCancel('__global__');

        self::assertSame([['stateKey' => '__global__', 'keepKinds' => []]], $once->cancels);
        self::assertFalse($once->isEmpty());
    }
}
