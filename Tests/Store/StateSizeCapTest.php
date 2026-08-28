<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Store;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Exception\SagaStateTooLarge;
use Storm\Saga\Store\Dbal\DbalWorkflowInstanceStore;
use Storm\Saga\Store\WorkflowInstanceRow;
use Storm\Saga\Store\WorkflowStatus;

/**
 * The ceiling on a saga's JSON bags. Not a storage limit, since jsonb would take megabytes, but a
 * design signal: an orchestration state holds ids, amounts and flags, a few hundred bytes across all
 * four bags, so a row orders of magnitude past that is a bag being appended to on every step.
 */
final class StateSizeCapTest extends TestCase
{
    #[Test]
    public function a_state_of_ordinary_size_is_written(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(2))->method('executeStatement')->willReturn(1);

        new DbalWorkflowInstanceStore($connection)->create($this->rowWith(['ref' => str_repeat('x', 300)]));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_bag_that_grew_past_the_cap_is_refused_before_the_write(): void
    {
        // the shape this catches: a bag appended to on every step. Nothing is written, so the step
        // rolls back rather than persisting a state the next transition would have to carry again.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->expectException(SagaStateTooLarge::class);
        $this->expectExceptionMessageIsOrContains('over the 8192-byte cap');

        new DbalWorkflowInstanceStore($connection)->create($this->rowWith(['log' => str_repeat('x', 9000)]));
    }

    #[Test]
    public function the_message_names_the_bag_that_grew(): void
    {
        // the total says there is a problem; only the breakdown says where, and a developer staring at
        // four bags needs the second
        $connection = $this->createStub(Connection::class);

        try {
            new DbalWorkflowInstanceStore($connection)->update($this->rowWith(['blob' => str_repeat('x', 9000)]));
            $this->fail('an oversized state must be refused');
        } catch (SagaStateTooLarge $e) {
            $this->assertStringContainsString('vars=', $e->getMessage());
            $this->assertStringContainsString('context=', $e->getMessage());
            $this->assertStringContainsString('payment/o-1', $e->getMessage());
        }
    }

    #[Test]
    public function the_cap_counts_every_bag_together_not_each_alone(): void
    {
        // the row pays the SUM, and one update rewrites all four, so four bags just under the cap are
        // four times the problem the cap exists to catch
        $connection = $this->createStub(Connection::class);
        $half = str_repeat('x', 5000);

        $this->expectException(SagaStateTooLarge::class);

        new DbalWorkflowInstanceStore($connection)->update(new WorkflowInstanceRow(
            'payment', 'o-1', 'charge', WorkflowStatus::Running,
            vars: ['a' => $half], context: ['b' => $half],
        ));
    }

    #[Test]
    public function the_boundary_is_inclusive_to_the_byte(): void
    {
        // pins BOTH the accumulator's start and the comparison's strictness. The three empty bags encode
        // as `{}`, `[]`, `{}`, six bytes, and `{"v":"…"}` wraps the payload in eight, so a vars string
        // of 8178 lands the total exactly ON the cap. A total counted one byte off, or a check that
        // fired AT the limit rather than past it, would survive every test that only tries round sizes.
        $exact = str_repeat('x', 8192 - 6 - 8);
        $this->assertSame(8192, strlen((string) json_encode(['v' => $exact])) + 6);

        $accepting = $this->createMock(Connection::class);
        $accepting->expects($this->exactly(2))->method('executeStatement')->willReturn(1);
        new DbalWorkflowInstanceStore($accepting)->create($this->rowWith(['v' => $exact]));

        $this->expectException(SagaStateTooLarge::class);

        new DbalWorkflowInstanceStore($this->createStub(Connection::class))
            ->create($this->rowWith(['v' => $exact.'x'])); // one byte past: refused
    }

    #[Test]
    public function the_breakdown_leads_with_the_biggest_bag(): void
    {
        // a developer staring at four bags reads the first one named; if the order were incidental the
        // message would point at whichever bag happened to come first in the array
        $connection = $this->createStub(Connection::class);

        try {
            new DbalWorkflowInstanceStore($connection)->update(new WorkflowInstanceRow(
                'payment', 'o-1', 'charge', WorkflowStatus::Running,
                vars: ['small' => 'x'], context: ['huge' => str_repeat('x', 9000)],
            ));
            $this->fail('an oversized state must be refused');
        } catch (SagaStateTooLarge $e) {
            $message = $e->getMessage();
            $this->assertLessThan(
                strpos($message, 'vars='),
                strpos($message, 'context='),
                'the heaviest bag is named first',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function rowWith(array $vars): WorkflowInstanceRow
    {
        return new WorkflowInstanceRow('payment', 'o-1', 'charge', WorkflowStatus::Running, $vars);
    }
}
