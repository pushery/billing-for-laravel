<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Resources\Payment;
use Pushery\Billing\Drivers\Mollie\Sandbox\SandboxMollie;
use Pushery\Billing\Support\SafeExternalUrl;

/**
 * The host Mollie serves its hosted checkout from, the page this driver sends a customer to for a first
 * payment, a one-time sale and a payment that still needs the customer: `www.mollie.com`, the origin
 * `MolliePaymentCsp` already lets that checkout frame from. Mollie can point a checkout straight at a bank
 * when the payment preselects an issuer, and this driver preselects none.
 */
final class MollieHostedPages
{
    /** @var list<string> */
    public const array HOSTS = ['www.mollie.com'];

    /**
     * The checkout of the payment where the driver may send the customer, or null: a page on one of the hosts above or
     * on one `billing.redirect_hosts` lists, and while the sandbox answers for Mollie, the sandbox's own page, but only
     * the one it handed out for this payment.
     */
    public static function checkout(Payment $payment): ?string
    {
        $url = $payment->getCheckoutUrl();

        if (SandboxMollie::enabled() && is_string($url) && SandboxMollie::fromConfig()->issuedCheckout(MollieValue::id($payment->id), $url)) {
            return SafeExternalUrl::orNull($url);
        }

        return SafeExternalUrl::onHosts($url, self::HOSTS);
    }
}
