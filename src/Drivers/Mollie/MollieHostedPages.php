<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

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
}
