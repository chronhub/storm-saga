<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow\Cadence;

use Cron\CronExpression;
use DateTimeInterface;
use Exception;
use RuntimeException;
use Storm\Clock\PointInTime;
use Storm\Saga\Exception\InvalidResolvedCron;
use Storm\Saga\Exception\InvalidWorkflowDefinition;
use Storm\Saga\Workflow\CatchUpPolicy;

/**
 * A calendar cadence driven by a standard cron expression, such as `0 0 1 * *` for the 1st of each month.
 * It wraps the validated expression; the build proves it parses before this is constructed. `countDue`
 * walks the expression forward from `$after`, bounded by the policy's `MAX_TICKS`, so a tight expression
 * over a long gap can never loop unbounded.
 *
 * The grid is absolute: the `$anchor`, the instance's birth, is ignored, so every instance fires on the
 * same calendar slots, the 1st of the month being the 1st for all. That is the global counterpart to
 * interval cadence per-instance phasing: pick cron when the rhythm belongs to the calendar, an anchored
 * interval when it belongs to each instance's own anniversary.
 *
 * The grid is UTC: the expression is evaluated in the framework's canonical instant, `PointInTime` in UTC,
 * so `0 2 * * *` means 02:00 UTC, not 02:00 in any market. A market-local recurrence is a
 * `BusinessCalendar` concern, not a cron offset.
 *
 * @see InvalidWorkflowDefinition::invalidCron()
 * @see IntervalCadence
 */
final readonly class CronCadence implements Cadence
{
    private CronExpression $expression;

    public function __construct(string $expression)
    {
        $this->expression = new CronExpression($expression);
    }

    /**
     * The declared cron string, readable back for description surfaces: the parsed form stays
     * private to the firing math, but what the dev DECLARED is not a secret; `storm:describe`
     * renders it, and a hidden expression leaves a fixed-cron schedule unreadable in the document.
     */
    public function expression(): string
    {
        return $this->expression->getExpression() ?? '';
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidResolvedCron when the expression parses but can never be satisfied, such as Feb 30
     */
    public function nextFireAfter(PointInTime $now, PointInTime $anchor): PointInTime
    {
        return PointInTime::fromDateTime($this->next($now));
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidResolvedCron when the expression parses but can never be satisfied, such as Feb 30
     */
    public function countDue(PointInTime $after, PointInTime $through, PointInTime $anchor): int
    {
        $count = 0;
        $cursor = $after;

        // @infection-ignore-all: the MAX_TICKS bound is an unbounded-loop backstop; reaching it at 10k occurrences is not unit-reachable
        while ($count < CatchUpPolicy::MAX_TICKS) {
            $next = PointInTime::fromDateTime($this->next($cursor));
            if ($next->isAfter($through)) {
                break;
            }

            $count++;
            $cursor = $next;
        }

        return $count;
    }

    /**
     * The library's walk, wrapped: `getNextRunDate` throws a raw RuntimeException when a
     * parseable-but-unsatisfiable expression such as Feb 30 exhausts its iterations; unwrapped, that poison
     * would roll back the step, redeliver, and DLQ for an authoring or data bug. The cadence owns the
     * failure type {@see InvalidResolvedCron}, so the tick fails as what it is.
     *
     * @throws Exception forwarded when the underlying date arithmetic fails for another reason
     * @throws InvalidResolvedCron when the expression can never be satisfied
     */
    private function next(PointInTime $from): DateTimeInterface
    {
        try {
            return $this->expression->getNextRunDate($from);
        } catch (RuntimeException $e) {
            throw InvalidResolvedCron::unsatisfiable($this->expression->getExpression() ?? '?', $e);
        }
    }
}
