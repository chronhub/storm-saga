<?php

declare(strict_types=1);

namespace Storm\Saga\Exception;

use Throwable;

/**
 * Exception root of the Saga module: every exception this module raises implements it, so a caller can
 * catch the whole family with `catch (SagaException)` without enumerating the concrete classes.
 *
 * An interface, not a base class, the shape Clock uses for `ClockException`, because the family spans
 * two SPL hierarchies a single base could not. Declaration, wiring and definition faults extend
 * `LogicException`; they are bugs, caught at build, that fail fast. Operational conditions extend
 * `RuntimeException`; a storage failure, an OCC conflict, a held fence or a runtime-resolved cron is
 * legitimately catch-and-retry. The SPL base keeps that bug-versus-operational distinction; this
 * interface adds only the family identity.
 *
 * It lives in the module, not in `Storm\Contracts\Saga`, by the placement rule the other modules
 * follow: a contracted exception interface is promoted to Contracts only when something outside the
 * module must name it, such as a Contracts-layer port `@throws` or a cross-module catch. Saga has
 * neither; its ports stay in the module and the bundle may depend on Saga directly. Should that change,
 * add `Storm\Contracts\Saga\SagaExceptionContract` above this and let this extend it, the two-tier
 * pattern Clock uses as `ClockException implements ClockExceptionContract`.
 */
interface SagaException extends Throwable {}
