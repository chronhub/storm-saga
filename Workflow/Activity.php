<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Throwable;

/**
 * The user-facing SPI: an activity is the unit of work an activity-state runs. It receives the workflow's
 * `vars` state bag and the run `Metadata`, and returns an `ActivityResult` of success with updated vars,
 * failure, or async. It is pure of the engine, with no DB and no bus awareness beyond what the app injects
 * into the implementation.
 */
interface Activity
{
    /**
     * Runs the activity against the current vars and reports its verdict.
     *
     * @param  array<string, mixed>  $vars  the workflow's current state bag
     *
     * @throws Throwable any failure of the work; the engine catches it and treats it as a failure result,
     *                   after which the retry or fallback policy applies
     */
    public function run(array $vars, Metadata $metadata): ActivityResult;
}
