<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

/**
 * Decides the priority lane a saga-issued command rides: the transport it is routed onto ahead of fresh
 * work for finish-over-start, or null to keep the command's normal class routing. Extracted from the
 * publisher so the lane decision as intent is decoupled from the dispatch as realization; the publisher
 * only stamps what the policy returns. A pure Saga port: it names a lane, an opaque transport id, and
 * does not know that id is a Messenger transport.
 *
 * The command itself is passed in, not just the workflow type, so a policy can decide per-leg, routing
 * different commands of the SAME workflow onto different lanes, without the publisher changing.
 *
 * @see SagaCommandPublisher the dispatch side that consults this and stamps the returned lane
 */
interface SagaLanePolicy
{
    /**
     * The lane, an opaque transport id, that a saga-issued command rides, or null to keep the command's
     * class routing.
     */
    public function laneFor(object $command, string $workflowType): ?string;
}
