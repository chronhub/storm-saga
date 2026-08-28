<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Child;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Saga\Child\ChildCorrelation;
use Storm\Saga\Exception\InvalidChildIdentity;

final class ChildCorrelationTest extends TestCase
{
    #[Test]
    public function mints_the_deterministic_child_identity(): void
    {
        $minted = ChildCorrelation::mint('order-1', 'kyc');

        $this->assertSame('order-1'.ChildCorrelation::DELIMITER.'kyc', $minted);
        $this->assertTrue(ChildCorrelation::isChild($minted));
        $this->assertFalse(ChildCorrelation::isChild('order-1'));
    }

    #[Test]
    public function the_same_parent_and_slot_always_mint_the_same_identity(): void
    {
        $this->assertSame(
            ChildCorrelation::mint('order-1', 'kyc'),
            ChildCorrelation::mint('order-1', 'kyc'),
        );
    }

    #[Test]
    public function a_grandchild_splits_on_the_last_delimiter(): void
    {
        $child = ChildCorrelation::mint('root-1', 'kyc');
        $grandchild = ChildCorrelation::mint($child, 'screening');

        $this->assertSame(
            ['parentCorrelationId' => $child, 'slot' => 'screening'],
            ChildCorrelation::split($grandchild),
        );
    }

    #[Test]
    public function the_root_of_a_lineage_is_the_segment_before_the_first_delimiter(): void
    {
        $child = ChildCorrelation::mint('root-1', 'kyc');
        $grandchild = ChildCorrelation::mint($child, 'screening');

        $this->assertSame('root-1', ChildCorrelation::root($child));
        $this->assertSame('root-1', ChildCorrelation::root($grandchild));

        // the discriminant against split(): a grandchild's PARENT is still a child, its root is not
        $this->assertNotSame(ChildCorrelation::split($grandchild)['parentCorrelationId'], ChildCorrelation::root($grandchild));
    }

    #[Test]
    public function the_root_of_a_native_correlation_is_itself(): void
    {
        $this->assertSame('order-1', ChildCorrelation::root('order-1'));
    }

    #[Test]
    public function splitting_a_native_correlation_is_refused(): void
    {
        $this->expectException(InvalidChildIdentity::class);

        ChildCorrelation::split('order-1');
    }

    #[Test]
    public function an_empty_parent_correlation_cannot_mint(): void
    {
        $this->expectException(InvalidChildIdentity::class);

        ChildCorrelation::mint('', 'kyc');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlots(): iterable
    {
        yield 'empty' => [''];
        yield 'longer than 64' => [str_repeat('a', 65)];
        yield 'a space' => ['kyc review'];
        yield 'the delimiter itself' => ["kyc\x1freview"];
        yield 'a dot' => ['kyc.review'];
    }

    #[Test]
    #[DataProvider('invalidSlots')]
    public function a_slot_must_be_a_static_identifier(string $slot): void
    {
        $this->expectException(InvalidChildIdentity::class);

        ChildCorrelation::mint('order-1', $slot);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function memberSlots(): iterable
    {
        yield 'the first member' => ['leg-0', 'leg'];
        yield 'a two-digit member' => ['leg-12', 'leg'];
        yield 'a family whose own name carries a hyphen' => ['settlement-leg-3', 'settlement-leg'];
        yield 'a family whose own name carries a digit' => ['leg2-3', 'leg2'];
        yield 'the declaration itself is not a member' => ['leg', null];
        yield 'a static sibling that merely looks like one' => ['leg-final', null];
        yield 'a trailing hyphen ends no member' => ['leg-', null];
        yield 'digits alone name no family' => ['7', null];
        yield 'a second line cannot smuggle a family in' => ["x\nleg-1", null];
    }

    #[Test]
    #[DataProvider('memberSlots')]
    public function a_member_slot_names_the_family_it_belongs_to(string $slot, ?string $family): void
    {
        // the one home of the family grammar: the consent proof resolves a member's declaration
        // through it, and a settling member reads it to know whether it can complete a family. A
        // static slot named `leg-final` beside a family `leg` is a different slot, not a fifth leg.
        $this->assertSame($family, ChildCorrelation::familyOfSlot($slot));
    }

    #[Test]
    public function the_refusal_message_escapes_the_control_delimiter(): void
    {
        try {
            ChildCorrelation::mint('order-1', "bad\x1fslot");
            $this->fail('expected InvalidChildIdentity');
        } catch (InvalidChildIdentity $e) {
            $this->assertStringNotContainsString("\x1f", $e->getMessage());
            $this->assertStringContainsString('\x1F', $e->getMessage());
        }
    }
}
