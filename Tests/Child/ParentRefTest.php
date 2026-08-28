<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Child;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Child\ParentRef;
use Storm\Saga\Exception\InvalidChildIdentity;

final class ParentRefTest extends TestCase
{
    #[Test]
    public function a_root_parent_declaration_round_trips_through_the_context(): void
    {
        $ref = new ParentRef('onboarding', 'ident-1', 'ident-1', 'kyc', 1);

        $read = ParentRef::fromContext([ParentRef::CONTEXT_KEY => $ref->toContext()]);

        $this->assertEquals($ref, $read);
        $this->assertSame(ChildCorrelation::mint('ident-1', 'kyc'), $ref->childCorrelationId());
    }

    #[Test]
    public function a_child_acting_as_parent_declares_the_chain_root_and_its_depth(): void
    {
        $childCorrelation = ChildCorrelation::mint('ident-1', 'kyc');

        $ref = new ParentRef('kyc_review', $childCorrelation, 'ident-1', 'screening', 2);

        $this->assertSame(ChildCorrelation::mint($childCorrelation, 'screening'), $ref->childCorrelationId());
    }

    #[Test]
    public function an_absent_declaration_reads_as_a_root(): void
    {
        $this->assertNull(ParentRef::fromContext([]));
        $this->assertNull(ParentRef::fromContext(['actor' => 'ops']));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'not an array' => ['ident-1', 'must be an array'];
        yield 'missing type' => [['correlation' => 'c', 'root' => 'c', 'slot' => 'kyc', 'depth' => 1], '"type" must be a string'];
        yield 'missing correlation' => [['type' => 'onboarding', 'root' => 'r', 'slot' => 'kyc', 'depth' => 1], '"correlation" must be a string'];
        yield 'non-string root' => [['type' => 'onboarding', 'correlation' => 'c', 'root' => 7, 'slot' => 'kyc', 'depth' => 1], '"root" must be a string'];
        yield 'non-int depth' => [['type' => 'onboarding', 'correlation' => 'c', 'root' => 'c', 'slot' => 'kyc', 'depth' => '1'], '"depth" must be an integer'];
    }

    #[Test]
    #[DataProvider('malformedEntries')]
    public function a_present_but_malformed_declaration_is_never_read_as_a_root(mixed $entry, string $message): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains($message);

        ParentRef::fromContext([ParentRef::CONTEXT_KEY => $entry]);
    }

    #[Test]
    public function the_root_must_be_a_native_correlation(): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains('root correlation id');

        new ParentRef('kyc_review', ChildCorrelation::mint('a', 's1'), ChildCorrelation::mint('a', 'x'), 'kyc', 2);
    }

    #[Test]
    public function the_root_must_not_be_empty(): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains('root correlation id');

        new ParentRef('kyc_review', ChildCorrelation::mint('a', 's1'), '', 'kyc', 2);
    }

    #[Test]
    public function the_parent_workflow_type_must_not_be_empty(): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains('parent workflow type must not be empty');

        new ParentRef('', 'ident-1', 'ident-1', 'kyc', 1);
    }

    #[Test]
    public function the_parent_correlation_must_not_be_empty(): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains('parent correlation id must not be empty');

        new ParentRef('onboarding', '', 'ident-1', 'kyc', 1);
    }

    #[Test]
    public function the_slot_must_be_a_valid_static_identifier(): void
    {
        $this->expectException(InvalidChildIdentity::class);
        $this->expectExceptionMessageIsOrContains('Child slot "bad slot!" is invalid');

        new ParentRef('onboarding', 'ident-1', 'ident-1', 'bad slot!', 1);
    }

    #[Test]
    public function at_depth_one_the_parent_is_the_root(): void
    {
        $this->expectException(InvalidChildIdentity::class);

        new ParentRef('onboarding', 'ident-1', 'other-root', 'kyc', 1);
    }

    #[Test]
    public function past_depth_one_the_parent_must_itself_be_a_child(): void
    {
        $this->expectException(InvalidChildIdentity::class);

        new ParentRef('onboarding', 'ident-1', 'ident-1', 'kyc', 2);
    }

    #[Test]
    public function depth_starts_at_one(): void
    {
        $this->expectException(InvalidChildIdentity::class);

        new ParentRef('onboarding', 'ident-1', 'ident-1', 'kyc', 0);
    }
}
