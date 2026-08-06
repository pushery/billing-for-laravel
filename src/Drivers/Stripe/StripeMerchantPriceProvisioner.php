<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantAccountDirectory;
use Pushery\Billing\Contracts\MerchantPriceProvisioner;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\Money;
use RuntimeException;
use Stripe\StripeClient;

/**
 * Mints a Stripe price for a merchant-defined tier, on the merchant's own connected account.
 *
 * The price is created ON the connected account, so it belongs to the creator and is charged and paid out
 * through them — never on the platform. Idempotency rides on a deterministic lookup key built from the tier,
 * the amount and the interval: the provisioner asks the account for a price with that key before creating
 * one, so re-provisioning an unchanged tier returns the price it already has rather than a second identical
 * one. Because the amount is part of the key, a real price change resolves to no existing match and mints a
 * new price, which is the only correct move — a Stripe price is immutable and cannot be edited in place.
 */
final readonly class StripeMerchantPriceProvisioner implements MerchantPriceProvisioner
{
    public function __construct(
        private StripeClient $stripe,
        private MerchantAccountDirectory $accounts,
    ) {}

    public function provision(Model $merchant, string $tierKey, Money $amount, BillingInterval $interval): string
    {
        $account = $this->accounts->accountFor($merchant);

        if (! $account instanceof MerchantAccountReference) {
            throw new RuntimeException('Cannot provision a price for a merchant with no connected account.');
        }

        $options = ['stripe_account' => $account->accountId];
        $lookupKey = $this->lookupKey($tierKey, $amount, $interval);

        // Ask the connected account for the price this exact tier/amount/interval already minted. Finding one
        // is the idempotent path: the same combination must never create a second identical price.
        $existing = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1], $options);

        if ($existing->data !== []) {
            return (string) $existing->data[0]->id;
        }

        $key = $merchant->getKey();

        $price = $this->stripe->prices->create([
            'unit_amount' => $amount->minorUnits,
            'currency' => strtolower($amount->currency),
            'recurring' => ['interval' => $interval->value],
            'lookup_key' => $lookupKey,
            'product_data' => ['name' => $tierKey],
            'metadata' => [
                'billing_merchant_type' => $merchant->getMorphClass(),
                'billing_merchant_id' => is_scalar($key) ? (string) $key : '',
                'billing_tier_key' => $tierKey,
            ],
        ], $options);

        return (string) $price->id;
    }

    /**
     * The idempotency key. It carries the amount and interval on purpose: an unchanged tier resolves to the
     * same key and reuses its price, while a repriced tier resolves to a new key and mints a new one.
     */
    private function lookupKey(string $tierKey, Money $amount, BillingInterval $interval): string
    {
        return 'billing_'.$tierKey.'_'.$amount->minorUnits.'_'.strtolower($amount->currency).'_'.$interval->value;
    }
}
