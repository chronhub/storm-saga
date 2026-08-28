<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

/**
 * The DOMAIN category of an activity failure, declared by the activity at its catch site, never
 * derived from an exception class by the engine, since infra is not domain.
 *
 * - `Transient` names weather: worth retrying within the declared filters, counted by the circuit
 *   breaker.
 *
 * - `Rejected` names a business verdict, and it is final: never retried whatever the filters say,
 *   never counted by the breaker, since a refusal is not a rail failure, and the fallback chain does
 *   not run, so a stand-in cannot salvage what a rail just refused. Guarding every fallback
 *   candidate on verdict-vars is then optional rather than mandatory.
 *
 * A `null` kind leaves the failure unclassified: the string filters decide retries, and the chain
 * runs on any terminal failure. Classifying is opt-in per activity.
 *
 * @see ActivityResult::failure()
 */
enum FailureKind
{
    case Transient;

    case Rejected;
}
