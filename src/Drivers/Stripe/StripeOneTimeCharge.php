<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Catalogs\ConfigAddonCatalog;
use Pushery\Billing\Contracts\CanTransactMoney;
use Pushery\Billing\Contracts\OneTimeCharge;
use Pushery\Billing\Exceptions\EligibilityDenied;
use Pushery\Billing\ValueObjects\ClientIntent;
use RuntimeException;
use Stripe\StripeClient;

/**
 * A first-class, subscription-independent one-time purchase for the Stripe driver. It opens a hosted
 * Checkout Session for the add-on and returns the driver-shaped payload the front-end redirects to.
 * The price is resolved from the add-on KEY through the catalog — the client never submits a price
 * (anti-price-injection) — and the add-on key is stamped on the session metadata, which the webhook
 * mapper reads on completion to credit the owner exactly once. This is the front half of the add-on
 * money loop whose back half (credit) already ships.
 */
final readonly class StripeOneTimeCharge implements OneTimeCharge
{
    public function __construct(
        private StripeClient $stripe,
        private ConfigAddonCatalog $addons,
        private Repository $config,
        private CanTransactMoney $eligibility,
        private StripeCustomerRegistry $customers,
    ) {}

    public function purchase(Model $billable, string $addonKey): ClientIntent
    {
        // Defense in depth: refuse to open a paid checkout for an ineligible owner even if a caller
        // bypassed the UI eligibility guard.
        if (! $this->eligibility->check($billable)) {
            throw EligibilityDenied::forMoneyMovement();
        }

        $price = $this->addons->providerPriceFor($addonKey);

        if ($price === null) {
            throw new InvalidArgumentException("Add-on '{$addonKey}' is not purchasable (no provider price configured).");
        }

        $customerId = $this->customers->resolve($billable);

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [['price' => $price, 'quantity' => 1]],
            // The webhook mapper reads this on checkout.session.completed to credit the owner.
            'metadata' => ['addon_key' => $addonKey],
            'success_url' => $this->returnUrl('success_url'),
            'cancel_url' => $this->returnUrl('cancel_url'),
        ]);

        $url = $session->url ?? null;

        return new ClientIntent(
            driver: 'stripe',
            payload: ['checkout_url' => is_string($url) ? $url : '', 'session_id' => $session->id],
            offSessionCapable: false,
        );
    }

    /** A configured hosted-checkout return URL, or a loud error — Stripe cannot open checkout without it. */
    private function returnUrl(string $key): string
    {
        $url = $this->config->get("billing.checkout.{$key}");

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("billing.checkout.{$key} must be configured to open a hosted checkout.");
        }

        return $url;
    }
}
