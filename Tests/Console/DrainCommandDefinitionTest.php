<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Clock\FrozenClock;
use Storm\Saga\Console\RelaySagaOutboxCommand;
use Storm\Saga\Console\RunTimersCommand;
use Storm\Saga\Engine\SagaTimerTarget;
use Storm\Saga\Outbox\SagaCommandPublisher;
use Storm\Saga\Outbox\SagaOutboxRelay;
use Storm\Saga\Schedule\TimerRunner;
use Storm\Saga\Store\DueTimerQueue;
use Storm\Serializer\MessageSerializer;
use Symfony\Component\Console\Command\Command;

/**
 * The two drain commands declare the surface an operator and a supervisor call them with: their
 * name, a batch bound defaulting to 100, and the daemon loop's options.
 */
final class DrainCommandDefinitionTest extends TestCase
{
    #[Test]
    public function the_timer_drain_declares_its_batch_and_its_daemon_options(): void
    {
        $runner = new TimerRunner(
            $this->createStub(DueTimerQueue::class),
            $this->createStub(SagaTimerTarget::class),
            FrozenClock::at('2026-09-25T00:00:00.000000+00:00'),
        );

        $this->assertDrainSurface(new RunTimersCommand($runner), 'storm:saga:timers');
    }

    #[Test]
    public function the_outbox_relay_declares_its_batch_and_its_daemon_options(): void
    {
        $relay = new SagaOutboxRelay(
            $this->createStub(Connection::class),
            $this->createStub(MessageSerializer::class),
            $this->createStub(SagaCommandPublisher::class),
        );

        $this->assertDrainSurface(new RelaySagaOutboxCommand($relay), 'storm:saga:relay');
    }

    private function assertDrainSurface(Command $command, string $name): void
    {
        $this->assertSame($name, $command->getName());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->getOption('batch')->isValueRequired());
        $this->assertSame('100', $definition->getOption('batch')->getDefault());
        $this->assertTrue($definition->hasOption('daemon'));
        $this->assertTrue($definition->hasOption('sleep'));
        $this->assertTrue($definition->hasOption('time-limit'));
    }
}
