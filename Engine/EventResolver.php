<?php

declare(strict_types=1);

namespace Storm\Saga\Engine;

/**
 * Saga's port over the messaging world, so the pure engine stays decoupled and Saga depends on
 * nothing. The WaitRunner uses it to match a delivered event by its stable type alias and to read
 * its payload for a map-form `#[WaitFor(extract:)]`. The engine only knows this interface; the
 * bundle wires the real adapter `Storm\Symfony\MappedEventResolver`, over the event-type mapper and
 * the event's own `toPayload()`. It lives bundle-side on purpose, since an in-package impl would
 * couple Saga to Chronicler. Tests stub it.
 *
 * @see State\WaitRunner
 */
interface EventResolver
{
    /**
     * The event's stable type alias, such as `order.placed`, or null if it has none.
     */
    public function aliasFor(object $event): ?string;

    /**
     * The event's data as a JSON-serializable array, read by a map-form `#[WaitFor(extract:)]` to
     * pull declared fields into named `vars`.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(object $event): array;
}
