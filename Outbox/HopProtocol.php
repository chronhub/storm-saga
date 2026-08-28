<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

use Storm\Contracts\Message\MessageContext;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Header;
use Storm\Message\Message;
use Storm\Message\UuidV7MetaIdentityGenerator;
use Storm\Saga\Store\WorkflowId;

/**
 * The saga hop's CLOSED protocol: what a saga-born command carries, decided once, here.
 *
 * A workflow's outgoing command was born at the step, not dispatched through a bus: no enricher chain
 * has touched it. This object is the single place that knows what its `Message` must carry at birth:
 *
 *  - The type: the command's FQCN message type, so the relay can rebuild it.
 *
 *  - A stable message id: minted once at seal time; the at-least-once relay re-dispatches under the SAME
 *    id, which is what consumer-side dedup keys on. A fresh id per dispatch would only catch a broker
 *    redelivery, never a relay re-drain.
 *
 *  - The saga's own correlation id: the routing identity, since outcome events come back to THIS
 *    instance, never the ambient trace's.
 *
 *  - The ambient actor and tenant: the chain's root principal survives the hop, the publisher
 *    re-stamping them and the events the command triggers inheriting them. The actor travels as a pair
 *    or not at all: half a principal is poison downstream, rejected by the relay's stamp mapping; a
 *    timer-fired step has nothing bound, so its command honestly carries none.
 *
 *  - The ambient declared bag: already filtered to the declared keys when the context was bound, so
 *    the whole bag rides along; the framework never reads its values.
 *
 *  - No causation: re-derived at each hop from the handled message's id, never transported.
 *
 * Deliberately NOT the message-module enricher chain: that chain is an OPEN extension point that any
 * app-tagged enricher joins, with dispatch-time semantics where ambient correlation and causation win.
 * This wire is a closed framework protocol: the relay, dedup, and the failure listener all read it back;
 * nothing foreign may land on the row.
 *
 * The required `MessageContext` is the shared ambient holder, never a private instance, or the step
 * would read a context nobody binds. Wiring it required keeps a missing alias loud: the container build
 * fails instead of silently degrading every hop to anonymous.
 *
 * @see \Storm\Message\Header::MessageType the command's FQCN message type
 * @see \Storm\Message\Enricher\ContextEnricher the open, dispatch-time sibling of the actor/tenant copy
 * @see \Storm\Story\Stamp\ContextStamps the relay-side mapping that re-stamps and polices the pair
 * @see \Storm\Story\Middleware\DeduplicateConsumer what the stable id exists for
 * @see WorkflowOutbox the engine-facing front that applies this protocol before storage
 */
final readonly class HopProtocol
{
    public function __construct(
        private MessageContext $context,
        private MetaIdentityGenerator $identity = new UuidV7MetaIdentityGenerator,
    ) {}

    /**
     * @throws InvalidMessageException when the command is itself a Message; a caller bug, since
     *                                 workflows declare bare commands and sealing wraps exactly once
     */
    public function seal(WorkflowId $id, object $command): Message
    {
        $headers = [
            Header::MessageType->value => $command::class,
            Header::MessageId->value => $this->identity->generate(),
            Header::CorrelationId->value => $id->correlationId,
        ];

        if (($actorId = $this->context->actorId()) !== null && ($actorType = $this->context->actorType()) !== null) {
            $headers[Header::ActorId->value] = $actorId;
            $headers[Header::ActorType->value] = $actorType;
        }

        if (($tenantId = $this->context->tenantId()) !== null) {
            $headers[Header::TenantId->value] = $tenantId;
        }

        // The declared bag survives the hop like the root principal does: the ambient values were
        // already filtered to the declared keys at bind time, so the copy is the whole bag.
        foreach ($this->context->bag() as $key => $value) {
            $headers[$key] = $value;
        }

        return new Message($command, $headers);
    }
}
