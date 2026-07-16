<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\PaymentCsp;

/**
 * The CSP sources Stripe.js needs to mount the payment element and confirm intents: the script itself,
 * the frames it opens for 3-D Secure and hosted fields, and the API it talks to. Scoped by the account
 * hub to its own routes, so js.stripe.com is permitted on the billing screens only.
 */
final class StripePaymentCsp implements PaymentCsp
{
    public function directives(): array
    {
        return [
            'script-src' => ['https://js.stripe.com'],
            'frame-src' => ['https://js.stripe.com', 'https://hooks.stripe.com'],
            'connect-src' => ['https://api.stripe.com'],
        ];
    }
}
