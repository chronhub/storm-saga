<?php

declare(strict_types=1);

namespace Storm\Saga\Workflow;

use Storm\Saga\Attributes\Workflow;

/**
 * What a correlation may do once its run has ended, the workflow's reuse policy, declared on
 * `#[Workflow(reuse:)]` and enforced by the schema, not by a runtime check.
 *
 * `Reject`, the default, spends a correlation once: `workflow_correlations` carries a unique index
 * partial on this very policy, so a second claim under it is refused by the database. Pick it when the
 * correlation is minted per run, an order id, a transfer id, a payment intent, which is the common
 * case and the safe one. What it buys is an IDENTITY: no artifact of a past run can ever reach a later
 * one, because there is no later one.
 *
 * `Allow` lets the same business key run again, each run taking the next `generation`. Pick it when the
 * correlation names a durable entity legitimately orchestrated more than once, a customer, a card, a
 * subscription. What it buys is weaker and must be read as such: every artifact Storm itself writes,
 * the outbox rows and their provenance, is sealed to its generation, so the dead-letter of a past run
 * cannot settle the living one. An event arriving from OUTSIDE carries no generation and never
 * will, since a third party knows nothing of Storm's runs, so a late external fact correlated on the
 * business key remains indistinguishable from a current one. Under `Allow` that window is the app's to
 * close, by minting per-run keys for the external legs or by reconciling them.
 *
 * @see Workflow the declaration site
 */
enum CorrelationReuse: string
{
    case Reject = 'reject';

    case Allow = 'allow';
}
