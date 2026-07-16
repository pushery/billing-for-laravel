<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Support\CheckoutUrls;
use Stripe\StripeClient;

/**
 * Opens a Stripe customer-portal session for the billable and returns its short-lived URL. It degrades
 * to null — so the bridge falls back to the in-app screens rather than erroring — when the billable has
 * no Stripe customer or no return URL can be resolved for the portal to send the customer back to.
 */
final readonly class StripeHostedPortal implements HostedPortal
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private CheckoutUrls $urls,
    ) {}

    public function url(Model $billable): ?string
    {
        $customerId = $this->customers->find($billable);
        $returnUrl = $this->urls->portalReturnUrl();

        if ($customerId === null || $returnUrl === null) {
            return null;
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }
}
