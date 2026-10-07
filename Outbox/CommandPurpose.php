<?php

declare(strict_types=1);

namespace Storm\Saga\Outbox;

/**
 * Why the engine issued a `workflow_outbox` row, stamped once at write time: THE purpose vocabulary,
 * shared by the writers and the schema. An abort's recall reads it to touch forward effects only; an
 * undo or the engine's own family traffic is never recalled, since the saga already owes it.
 */
enum CommandPurpose: string
{
    /** A step's forward effect, the only purpose an abort's recall may touch. */
    case Forward = 'forward';

    /** An undo: a rollback's, or the one a race victory or a join failure issues for an arm in flight. */
    case Compensation = 'compensation';

    /** The engine's family traffic: a cascade's cancel of a living child, or a member's poke of its parent. */
    case Control = 'control';
}
