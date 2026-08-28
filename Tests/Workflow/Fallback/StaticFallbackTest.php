<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow\Fallback;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\Fallback\StaticFallback;
use Storm\Saga\Workflow\Metadata;

final class StaticFallbackTest extends TestCase
{
    #[Test]
    public function it_merges_its_defaults_over_the_incoming_vars_keeping_both(): void
    {
        // the static defaults override on a key clash, but the incoming vars survive the merge
        $fallback = new StaticFallback(['price' => 100]);

        $result = $fallback->execute(['order_id' => 'ord-1'], new Metadata('wf', 'corr-1'));

        $this->assertSame(ActivityOutcome::Success, $result->outcome);
        $this->assertSame(['order_id' => 'ord-1', 'price' => 100], $result->vars);
    }
}
