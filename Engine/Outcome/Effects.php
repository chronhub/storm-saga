<?php

declare(strict_types=1);

namespace Storm\Saga\Engine\Outcome;

use Storm\Saga\Engine\TimerOp;
use Storm\Saga\Event\SagaAnnouncement;

/**
 * No row change at all, an effect-gating wait's escalation: re-arm the heartbeat timer and announce,
 * and the instance row stays untouched at the same state and same version.
 */
final readonly class Effects implements StepOutcome
{
    /**
     * @param  list<TimerOp>  $timerOps
     * @param  list<SagaAnnouncement>  $announcements
     */
    public function __construct(
        public array $timerOps = [],
        public array $announcements = [],
    ) {}
}
