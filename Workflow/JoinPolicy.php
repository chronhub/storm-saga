<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\JoinArm;

/**
 * A join, assembled: the arms by name, and `joinedBy`, the issuing state's success-target wait the
 * saga only crosses once every arm has completed, derived from the graph at build and never
 * declared, so the topology cannot disagree with itself. The three lookups are the runtime's whole
 * use of it: which arm a command belongs to when the outbox row is stamped, which arm an arriving
 * outcome completes, and which arm an arriving failure condemns.
 *
 * @see JoinArm
 * @see RacePolicy the first-outcome dual
 */
final readonly class JoinPolicy
{
    /**
     * @param  array<string, JoinArmSlot>  $arms  keyed by arm name
     */
    public function __construct(
        public string $joinedBy,
        public array $arms,
    ) {}

    /**
     * The arm issuing `$commandClass`, the outbox stamping key; null when no arm owns it, which the
     * executor refuses loudly, since a join state may not smuggle an unattributed command.
     *
     * @param  class-string  $commandClass
     */
    public function armFor(string $commandClass): ?JoinArmSlot
    {
        return array_find($this->arms, fn ($arm) => $arm->command === $commandClass);
    }

    /**
     * The arm whose `completedBy` the arriving outcome satisfies, subtype included, mirroring how
     * the wait itself matches events; the build proved the arms' event classes cannot overlap.
     *
     * @param  class-string  $eventClass
     */
    public function completerFor(string $eventClass): ?JoinArmSlot
    {
        return array_find($this->arms, fn ($arm) => is_a($eventClass, $arm->completedBy, true));
    }

    /**
     * The arm whose `failedBy` the arriving event satisfies, the definitive per-arm failure that
     * disposes of the whole join. Null when the event condemns no arm.
     *
     * @param  class-string  $eventClass
     */
    public function failerFor(string $eventClass): ?JoinArmSlot
    {
        return array_find($this->arms, fn ($arm) => $arm->failedBy !== null && is_a($eventClass, $arm->failedBy, true));
    }

    /**
     * True when `$arrived`, the arm names marked in the engine's `arms` bag, covers every declared
     * arm: the last completion just landed and the joining wait may cross.
     *
     * @param  list<string>  $arrived
     */
    public function isComplete(array $arrived): bool
    {
        return array_all(array_keys($this->arms), fn (string $name): bool => in_array($name, $arrived, true));
    }
}
