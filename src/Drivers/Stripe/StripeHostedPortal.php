<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\HostedPortal;
use Pushery\Billing\Support\CheckoutUrls;
use Pushery\Billing\Support\SafeExternalUrl;
use Stripe\StripeClient;

/**
 * Opens a Stripe customer-portal session for the billable and returns its short-lived URL. It degrades
 * to null — so the bridge falls back to the in-app screens rather than erroring — when the billable has
 * no Stripe customer or no return URL can be resolved for the portal to send the customer back to.
 *
 * The session opens with the portal configuration `billing.stripe.portal_configuration` names, or with
 * the account's default when it names none. The configuration decides what a customer can do in the
 * portal, canceling a subscription included.
 */
final readonly class StripeHostedPortal implements HostedPortal
{
    public function __construct(
        private StripeClient $stripe,
        private StripeCustomerRegistry $customers,
        private CheckoutUrls $urls,
        private Repository $config,
    ) {}

    public function url(Model $billable, ?string $returnUrl = null): ?string
    {
        $customerId = $this->customers->find($billable);
        $returnUrl ??= $this->urls->portalReturnUrl();

        if ($customerId === null || $returnUrl === null) {
            return null;
        }

        $params = ['customer' => $customerId, 'return_url' => $returnUrl];
        $configuration = $this->configuration();

        if ($configuration !== null) {
            $params['configuration'] = $configuration;
        }

        $session = $this->stripe->billingPortal->sessions->create($params);

        return SafeExternalUrl::onHosts($session->url, StripeHostedPages::HOSTS);
    }

    /**
     * The `bpc_…` id the application names, or null for the account's default. A blank value names none.
     */
    private function configuration(): ?string
    {
        $configured = $this->config->get('billing.stripe.portal_configuration');

        return is_string($configured) && trim($configured) !== '' ? $configured : null;
    }
}
