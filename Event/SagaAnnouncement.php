<?php

declare(strict_types=1);

namespace Storm\Saga\Event;

/**
 * The identity every `Saga*` announcement carries: which saga spoke, and which of its runs. The
 * engine dispatches announcements post-commit as plain objects; this contract is what lets the
 * trail machinery treat them uniformly. The history entry extracts the trio structurally, and the
 * birth path re-stamps `generation` on announcements built before the registry handed the run
 * number back. An event that forgot the trio would not compile, which is the point: the refusal is
 * the type's, not a convention's.
 *
 * `generation` is the registry's 1-based run counter; 0 is the honest unknown, announced only by a
 * skip path that never held the claimed row.
 */
interface SagaAnnouncement
{
    public string $workflowType { get; }

    public string $correlationId { get; }

    public int $generation { get; }

    /**
     * The same announcement, stamped with the claimed run number. The birth path builds its
     * announcements before the registry hands the generation back, then re-stamps them; this is
     * `claimedAs()`'s twin for the trail.
     */
    public function withGeneration(int $generation): static;
}
