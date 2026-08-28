<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\Saga\Workflow\ActivityOutcome;
use Storm\Saga\Workflow\ActivityResult;
use Storm\Saga\Workflow\FailureKind;

final class ActivityResultTest extends TestCase
{
    #[Test]
    public function success_carries_the_updated_vars(): void
    {
        $result = ActivityResult::success(['chargeId' => 'ch_1']);

        $this->assertSame(ActivityOutcome::Success, $result->outcome);
        $this->assertSame(['chargeId' => 'ch_1'], $result->vars);
        $this->assertNull($result->error);
        $this->assertNull($result->asyncId);
        $this->assertNull($result->cause);
    }

    #[Test]
    public function failure_carries_the_error_and_optional_cause(): void
    {
        $cause = new RuntimeException('declined');
        $result = ActivityResult::failure('card declined', ['attempt' => 2], $cause);

        $this->assertSame(ActivityOutcome::Failure, $result->outcome);
        $this->assertSame('card declined', $result->error);
        $this->assertSame(['attempt' => 2], $result->vars);
        $this->assertSame($cause, $result->cause);
    }

    #[Test]
    public function async_carries_the_dispatched_work_id(): void
    {
        $result = ActivityResult::async('job-42', ['issued' => true]);

        $this->assertSame(ActivityOutcome::Async, $result->outcome);
        $this->assertSame('job-42', $result->asyncId);
        $this->assertSame(['issued' => true], $result->vars);
        $this->assertNull($result->error);
    }

    #[Test]
    public function a_failure_is_unclassified_unless_the_activity_declares_its_kind(): void
    {
        // null is the soft-migration path: every pre-kind failure behaves exactly as before
        $this->assertNull(ActivityResult::failure('boom')->kind);

        $classified = ActivityResult::failure('declined', kind: FailureKind::Rejected);
        $this->assertSame(FailureKind::Rejected, $classified->kind);
    }
}
