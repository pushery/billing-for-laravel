<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\PaymentCsp;

/**
 * The CSP sources Stripe.js needs to mount the payment element and confirm intents, as Stripe's security guide
 * (https://docs.stripe.com/security/guide) lists them for Stripe.js: the script itself, the frames it opens for
 * hosted fields and 3-D Secure, and the API it talks to. The subdomains of js.stripe.com are among them because
 * Stripe.js starts its frames on separate origins where it can. Scoped by the account hub to its own routes, so
 * these are permitted on the billing screens only.
 *
 * The guide's conditional entries are left to `account.csp.additional`, for a host that uses them: Link, and an
 * Address Element on the host's own Google Maps key.
 */
final class StripePaymentCsp implements PaymentCsp
{
    public function directives(): array
    {
        return [
            'script-src' => ['https://js.stripe.com', 'https://*.js.stripe.com'],
            'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com'],
            'connect-src' => ['https://api.stripe.com'],
        ];
    }
}
