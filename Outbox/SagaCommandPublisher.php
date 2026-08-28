<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Message\Message;
use Throwable;

/**
 * Dispatches a saga's outgoing command, rebuilt by `SagaOutboxRelay` from its `workflow_outbox` row, to
 * its target `$bus` such as `storm.command.bus`. Kept as a Saga-owned port so the relay stays decoupled
 * from Messenger; the real implementation dispatches onto the command bus and is wired with the rest of
 * the engine, while tests use a double.
 *
 * Throwing signals a delivery failure. By default the relay treats it as transient, bumping `attempts`,
 * backing off, and eventually dead-lettering; a publisher that knows the command is undeliverable throws
 * an `UnrecoverableCommandDispatch`, and the relay dead-letters it immediately. Succeed only when the
 * command is actually handed to the bus, and to THE bus asked for: an implementation bound to a single
 * bus MUST refuse a foreign `$bus` loud with `UnrecoverableCommandDispatch`, never silently reroute onto
 * one the writer did not name.
 *
 * @see SagaOutboxRelay
 */
interface SagaCommandPublisher
{
    /**
     * Dispatch the rebuilt `$message` to its `$bus` on behalf of the `$workflowType` saga.
     *
     * `$workflowType` lets a publisher route a workflow's commands onto its OWN priority lane for
     * per-workflow finish-over-start, not just a single global one.
     *
     * @throws UnrecoverableCommandDispatch when the command can never be delivered, such as no handler or
     *                                      an invalid payload; the relay dead-letters the row immediately
     * @throws Throwable any other delivery failure; the relay treats it as transient, backs off, then
     *                   dead-letters once the budget runs out
     */
    public function publish(Message $message, string $bus, string $workflowType): void;
}
