<?php

declare(strict_types=1);

namespace Storm\Saga\Attributes;

use Attribute;

/**
 * Declares which `vars` keys of this workflow MAY leave the engine: the exposure allowlist, compiled
 * beside the definition and read by every outward channel; the inspection snapshot the console and
 * the ops HTTP surface both serve, and one day the search index, which may only ever index a declared
 * key. The default is CLOSED: without this attribute nothing of the business bags is exposed anywhere.
 *
 * The mechanism is OMISSION, never masking: an undeclared key simply does not exist for a reader, so
 * neither its value nor its presence leaks. Declare only keys that are safe on an operator's screen
 * and in an HTTP response; a key that would need hashing or ciphering to be safe does not belong here.
 *
 * The declaration is per NAME, like `stateVersion` and the class-level `#[Prioritized]`: co-registered
 * versions of one name share their activities and one data contract, so they must declare the same
 * list, enforced at registration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExposesState
{
    /** @var list<string> */
    public array $keys;

    public function __construct(string ...$keys)
    {
        // @infection-ignore-all; equivalent: a variadic arrives packed and sequentially keyed, so this renumbers nothing
        $this->keys = array_values($keys);
    }
}
