<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Pushery\Billing\Contracts\PaymentCsp;

/**
 * The CSP sources Mollie's front end needs: the Components script that renders the card fields, the
 * frames it opens, the checkout the customer is redirected to, and the API those talk to.
 *
 * Scoped by the account hub to its own routes, so these are permitted on the billing screens only. That
 * scoping is what makes keeping the list short worth the effort — an origin here is not one more allowed
 * domain in general, it is one more origin allowed to run script on the pages where somebody types a card
 * number.
 *
 * `www.mollie.com` is in `frame-src` rather than only as a redirect target because the checkout is also
 * embeddable, and a driver whose hosted step silently fails to frame is indistinguishable to the customer
 * from a payment that did not work.
 */
final class MolliePaymentCsp implements PaymentCsp
{
    public function directives(): array
    {
        return [
            'script-src' => ['https://js.mollie.com'],
            'frame-src' => ['https://js.mollie.com', 'https://www.mollie.com'],
            'connect-src' => ['https://api.mollie.com'],
        ];
    }
}
