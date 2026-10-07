<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Engine\RecallVerdict;
use Storm\Saga\Workflow\CompensationRecord;
use Storm\Saga\Workflow\CompensationStatus;

/**
 * The recall's proof as a value: which entries it names.
 */
final class RecallVerdictTest extends TestCase
{
    #[Test]
    public function an_arm_is_recalled_by_its_own_key(): void
    {
        $left = CompensationRecord::forArm('race', 'left', CompensationStatus::Pending, false);
        $verdict = RecallVerdict::of($left);

        $this->assertTrue($verdict->recalls($left));
        $this->assertFalse($verdict->recalls(CompensationRecord::forArm('race', 'right', CompensationStatus::Pending, false)));
        $this->assertFalse($verdict->recalls(CompensationRecord::pending('race')));
    }

    #[Test]
    public function the_empty_verdict_recalls_nothing(): void
    {

        $this->assertFalse(RecallVerdict::none()->recalls(CompensationRecord::pending('charge')));
    }
}
