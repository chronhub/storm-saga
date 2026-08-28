<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Outbox;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Contracts\Message\MetaIdentityGenerator;
use Storm\Message\ContextValues;
use Storm\Message\Header;
use Storm\Saga\Outbox\HopProtocol;
use Storm\Saga\Store\WorkflowId;

/**
 * The closed protocol of the saga hop, clause by clause. Each test pins the COMPLETE header map,
 * with a fixed identity stub making it deterministic, so a dropped, leaked or swapped header fails
 * loudly. The end-to-end row shape stays with the integration WorkflowOutboxContextTest.
 */
final class HopProtocolTest extends TestCase
{
    #[Test]
    public function seals_type_stable_id_and_the_sagas_correlation_nothing_else(): void
    {
        $command = new stdClass;

        $message = $this->protocol(ContextValues::empty())->seal(new WorkflowId('transfer', 't-1'), $command);

        $this->assertSame($command, $message->message());
        $this->assertSame([
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-1',
        ], $message->headers());
    }

    #[Test]
    public function the_ambient_principal_travels_whole(): void
    {
        $context = new ContextValues(actorId: 'user-1', actorType: 'user', tenantId: 'acme');

        $message = $this->protocol($context)->seal(new WorkflowId('transfer', 't-1'), new stdClass);

        $this->assertSame([
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-1',
            Header::ActorId->value => 'user-1',
            Header::ActorType->value => 'user',
            Header::TenantId->value => 'acme',
        ], $message->headers());
    }

    #[Test]
    #[Group('adversarial')]
    public function half_a_principal_does_not_travel(): void
    {
        // half a pair is poison downstream, since the relay's stamp mapping rejects it; the hop
        // degrades to anonymous rather than fabricating a malformed row, in both orientations
        $base = [
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-1',
        ];

        $idOnly = $this->protocol(new ContextValues(actorId: 'user-1'))
            ->seal(new WorkflowId('transfer', 't-1'), new stdClass);
        $typeOnly = $this->protocol(new ContextValues(actorType: 'user'))
            ->seal(new WorkflowId('transfer', 't-1'), new stdClass);

        $this->assertSame($base, $idOnly->headers());
        $this->assertSame($base, $typeOnly->headers());
    }

    #[Test]
    public function the_tenant_travels_alone(): void
    {
        $message = $this->protocol(new ContextValues(tenantId: 'acme'))
            ->seal(new WorkflowId('transfer', 't-1'), new stdClass);

        $this->assertSame([
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-1',
            Header::TenantId->value => 'acme',
        ], $message->headers());
    }

    #[Test]
    public function the_declared_bag_survives_the_hop(): void
    {
        $message = $this->protocol(new ContextValues(bag: ['origin' => 'http']))
            ->seal(new WorkflowId('transfer', 't-1'), new stdClass);

        $this->assertSame([
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-1',
            'origin' => 'http',
        ], $message->headers());
    }

    #[Test]
    #[Group('adversarial')]
    public function the_ambient_trace_never_leaks_correlation_stays_the_sagas(): void
    {
        // the bound trace carries its own correlation and causation: neither may land on the
        // wire; correlation is the saga's routing identity, causation is re-derived per hop
        $context = new ContextValues(correlationId: 'outer-trace', causationId: 'cause-1');

        $message = $this->protocol($context)->seal(new WorkflowId('transfer', 't-9'), new stdClass);

        $this->assertSame([
            Header::MessageType->value => stdClass::class,
            Header::MessageId->value => 'id-1',
            Header::CorrelationId->value => 't-9',
        ], $message->headers());
    }

    #[Test]
    public function each_seal_mints_its_own_stable_id(): void
    {
        // the default generator: one id per seal, stable on the row, never shared across seals
        $protocol = new HopProtocol(ContextValues::empty());

        $first = $protocol->seal(new WorkflowId('transfer', 't-1'), new stdClass)->messageId();
        $second = $protocol->seal(new WorkflowId('transfer', 't-1'), new stdClass)->messageId();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    private function protocol(ContextValues $context): HopProtocol
    {
        $identity = new class() implements MetaIdentityGenerator
        {
            public function generate(): string
            {
                return 'id-1';
            }
        };

        return new HopProtocol($context, $identity);
    }
}
