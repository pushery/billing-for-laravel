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
 * Link's sources come with `link: true`, for a page that offers Link, as the same guide lists them. An Address
 * Element on the host's own Google Maps key stays with `account.csp.additional`. Apple Pay, Google Pay, PayPal and
 * Klarna run inside these frames or redirect through them, and the guide lists no further source for them.
 */
final readonly class StripePaymentCsp implements PaymentCsp
{
    /**
     * @param  bool  $link  whether the page offers Link, whose sign-in and assets Stripe serves from `link.com`
     */
    public function __construct(
        private bool $link = false,
    ) {}

    public function directives(): array
    {
        $directives = [
            'script-src' => ['https://js.stripe.com', 'https://*.js.stripe.com'],
            'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com'],
            'connect-src' => ['https://api.stripe.com'],
        ];

        if (! $this->link) {
            return $directives;
        }

        return [
            ...$directives,
            'frame-src' => [...$directives['frame-src'], 'https://link.com', 'https://*.link.com'],
            'connect-src' => [...$directives['connect-src'], 'https://link.com', 'https://*.link.com'],
            'img-src' => ['https://*.link.com'],
        ];
    }
}
