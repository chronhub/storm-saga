<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\RaceArm;

/**
 * A race, assembled: the arms by name, and `settledBy`, the issuing state's success-target wait
 * where the first outcome wins, derived from the graph at build and never declared, so the topology
 * cannot disagree with itself. The two lookups are the runtime's whole use of it: which arm a
 * command belongs to when the outbox row is stamped, and which arm an arriving outcome crowns.
 *
 * @see RaceArm
 * @see JoinPolicy the every-arm dual
 */
final readonly class RacePolicy
{
    /**
     * @param  array<string, RaceArmSlot>  $arms  keyed by arm name
     */
    public function __construct(
        public string $settledBy,
        public array $arms,
    ) {}

    /**
     * The arm issuing `$commandClass`, the outbox stamping key; null when no arm owns it, which the
     * executor refuses loudly, since a race state may not smuggle an unattributed command.
     *
     * @param  class-string  $commandClass
     */
    public function armFor(string $commandClass): ?RaceArmSlot
    {
        return array_find($this->arms, fn ($arm) => $arm->command === $commandClass);
    }

    /**
     * The arm whose `wonBy` the arriving outcome satisfies, subtype included, mirroring how the wait
     * itself matches events; the build proved the arms' outcome classes cannot overlap.
     *
     * @param  class-string  $eventClass
     */
    public function winnerFor(string $eventClass): ?RaceArmSlot
    {
        return array_find($this->arms, fn ($arm) => is_a($eventClass, $arm->wonBy, true));
    }

    /**
     * Every arm except `$winner`, the disposition list of one victory.
     *
     * @return list<RaceArmSlot>
     */
    public function losersTo(RaceArmSlot $winner): array
    {
        return array_values(array_filter($this->arms, static fn (RaceArmSlot $arm): bool => $arm->name !== $winner->name));
    }
}
