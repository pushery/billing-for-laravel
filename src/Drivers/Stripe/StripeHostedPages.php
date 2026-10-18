<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

/**
 * The hosts Stripe serves the pages this driver sends somebody to, as Stripe's API reference gives them:
 * Checkout on `https://checkout.stripe.com` (the subscription checkout, a one-time or routed sale, a tip and the
 * page that adds a card), the customer portal on `https://billing.stripe.com`, and the onboarding of a connected
 * account on `https://connect.stripe.com`.
 *
 * A Stripe account with a custom domain gets Checkout and the portal on that domain instead. The install's own
 * account lists it in `billing.redirect_hosts`, and a payment account names its own in
 * `PaymentAccount::$redirectHosts`, which only that account's checkout accepts. A session that carries
 * `on_behalf_of` stays on `checkout.stripe.com` either way.
 */
final class StripeHostedPages
{
    /** @var list<string> */
    public const array HOSTS = ['checkout.stripe.com', 'billing.stripe.com', 'connect.stripe.com'];
}
