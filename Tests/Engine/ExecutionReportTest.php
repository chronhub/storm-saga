<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Engine;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Engine\ExecutionReport;

final class ExecutionReportTest extends TestCase
{
    #[Test]
    public function only_applied_collapses_to_true(): void
    {
        // the public bool the apps see: every no-op cause, retryable or not, reads false
        $this->assertTrue(ExecutionReport::Applied->applied());
        $this->assertFalse(ExecutionReport::NothingToDo->applied());
        $this->assertFalse(ExecutionReport::NotYetApplicable->applied());
        $this->assertFalse(ExecutionReport::FenceBusy->applied());
    }
}
