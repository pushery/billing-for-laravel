<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Pushery\Billing\Events\PaymentSucceeded;
use Pushery\Billing\Support\BillingManager;
use Pushery\Billing\Support\LocalBillingEngine;

/**
 * Closes a billing cycle whose charge settled after the tick that started it had already gone home.
 *
 * ## Why this had to exist before the pending branch could
 *
 * A charge is not always answered while the sweep is still running. A SEPA direct debit — the main
 * recurring method Mollie offers in Europe — is accepted immediately and settles days later. Until now the
 * only writer of a paid order was the engine's own synchronous `recordSuccess()`, so a charge that settled
 * later had nowhere to land, and the engine booked "not settled yet" as a refusal: the customer was mailed
 * "your payment failed" while their money was on its way, and the dunning ladder charged them again days
 * afterwards, by which time no idempotency key could collapse it.
 *
 * Holding the cycle open instead is only honest if something eventually closes it. That is this.
 *
 * ## It is a lookup and a handoff, and nothing else
 *
 * Every decision about the cycle — advancing the period, clearing the dunning state, raising the invoice —
 * stays in the engine that owns them. An effect that reproduced any of it would be a second opinion about
 * a subscription's state, and the two would disagree the first time either was edited alone.
 *
 * ## It selects itself
 *
 * Only a LOCAL engine holds a cycle open, so only a local engine has one of these to settle. A driver whose
 * provider runs the subscription answers its own cycles through its own events, and this finds nothing for
 * them — no driver name appears in the decision.
 */
final readonly class SettleCycleOnPayment
{
    public function __construct(private BillingManager $manager) {}

    public function __invoke(PaymentSucceeded $event): void
    {
        // Resolved through the MANAGER, which is what everything else in this package does and what the
        // container can actually build. `BillingEngine` is an interface bound NOWHERE — the engine is
        // reached only as `driver()->engine()` — so injecting it would make this class unconstructible, and
        // `HandleWebhookEffect` builds effects with `Container::make()`. Every held cycle would then stay
        // held forever while every test stayed green, because a test that constructs the engine by hand
        // never asks the container the question a real delivery asks.
        $engine = $this->manager->driver()->engine();

        if (! $engine instanceof LocalBillingEngine) {
            return;
        }

        $engine->settle($event->reference, $event->amount->currency);
    }
}
