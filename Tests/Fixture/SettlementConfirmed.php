<?php

declare(strict_types=1);

namespace Storm\Saga\Tests\Fixture;

/**
 * A concrete refinement of {@see AbstractSettlement}, and the only class pair in these fixtures
 * standing in a STRICT subtype relation.
 *
 * That strictness is what it exists for. The build rules refuse two arms whose outcomes overlap, and
 * they test the relation in both directions because subtyping runs one way: with two identical
 * classes both directions hold and a one-directional check would read as correct. Only a strict pair
 * tells the two apart.
 */
final class SettlementConfirmed extends AbstractSettlement {}
