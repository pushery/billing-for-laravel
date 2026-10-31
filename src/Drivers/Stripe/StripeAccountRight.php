<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

/**
 * A right the key of a Stripe payment account needs for what the package does with the account.
 *
 * A secret key holds every right. A restricted key holds the ones the merchant gave it, and Stripe names them
 * nowhere, so {@see StripeAccountSetup::missingRights()} asks for each.
 */
enum StripeAccountRight: string
{
    /** Hosted Checkout Sessions on the account. */
    case CheckoutSessionsWrite = 'checkout_sessions_write';

    /** Charges: payment intents, created and confirmed. */
    case PaymentIntentsWrite = 'payment_intents_write';

    /** Reading a payment back, which the account's webhooks do for every payment they report. */
    case PaymentIntentsRead = 'payment_intents_read';

    /** Reading the disputes of a payment, which a change about a refund or a dispute lists. */
    case DisputesRead = 'disputes_read';

    /** Reading a payment method a charge or a mandate names. */
    case PaymentMethodsRead = 'payment_methods_read';

    /** Refunds. */
    case RefundsWrite = 'refunds_write';

    /** The account's webhook endpoint, set up and removed with {@see StripeAccountSetup}. */
    case WebhookEndpointsWrite = 'webhook_endpoints_write';

    /** The payment methods the account has turned on, for `BillingManager::accountPaymentMethods()`. */
    case PaymentMethodConfigurationsRead = 'payment_method_configurations_read';

    /** A shop's domain for the wallets on its page, for {@see StripePaymentMethodDomains}. */
    case PaymentMethodDomainsWrite = 'payment_method_domains_write';
}
