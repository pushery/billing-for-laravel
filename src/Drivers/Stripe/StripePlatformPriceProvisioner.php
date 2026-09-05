<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\MerchantPriceProvisioner;
use Pushery\Billing\Enums\BillingInterval;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * Mints a Stripe price for a merchant-defined tier, on the PLATFORM account.
 *
 * The twin of {@see StripeMerchantPriceProvisioner}, and which of the two applies is not a separate choice:
 * it follows from the seller-of-record posture. Under `platform_deemed_supplier` the platform is the seller
 * to the buyer and the checkout session runs on the platform account, so a price minted on the merchant's
 * connected account is one that session cannot use. That posture is not a preference for an electronic
 * supply either -- Art. 9a of the VAT Implementing Regulation makes it irrebuttable for a platform that sets
 * the terms -- so a content marketplace needs exactly this combination: per-merchant tiers, platform prices.
 *
 * THE LOOKUP KEY CARRIES THE MERCHANT, AND THAT IS THE WHOLE DIFFERENCE FROM THE TWIN.
 *
 * On separate connected accounts, `tier + amount + interval` is unique by construction: two creators each
 * have their own account and cannot collide. On ONE shared platform account they can, and the failure would
 * not be an error -- it would be two creators silently sharing a price, so one creator's repricing moves the
 * other's tier. That is the kind of defect that surfaces as a billing dispute rather than as a stack trace.
 *
 * The merchant enters the key as a short digest rather than as its identifiers. A morph class is a fully
 * qualified class name, backslashes and all, and Stripe's lookup key is a bounded, restricted string; the
 * readable identity is in `metadata`, which is where somebody debugging will look anyway.
 *
 * No connected account is required here, and that is deliberate rather than an omission. Minting a price on
 * the platform account needs nothing from the merchant, so demanding an account would refuse a tier that
 * could be created and sold perfectly well. Whether the merchant can RECEIVE the money is a different
 * question, asked at checkout by the receive gate, where it belongs.
 */
final readonly class StripePlatformPriceProvisioner implements MerchantPriceProvisioner
{
    public function __construct(private StripeClient $stripe) {}

    public function provision(Model $merchant, string $tierKey, Money $amount, BillingInterval $interval): string
    {
        $lookupKey = $this->lookupKey($merchant, $tierKey, $amount, $interval);

        // The platform account, so no `stripe_account` option -- the absence is the behavior, which is why
        // the test asserts on the recorded request rather than on this line.
        $existing = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'limit' => 1]);

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
        ]);

        return (string) $price->id;
    }

    /**
     * The idempotency key, scoped to the merchant because the platform account is shared.
     *
     * Amount and interval are in it for the same reason as in the twin: an unchanged tier resolves to the
     * same key and reuses its price, a repriced one resolves to a new key and mints a new price. A Stripe
     * price is immutable, so minting is the only way to reprice.
     */
    private function lookupKey(Model $merchant, string $tierKey, Money $amount, BillingInterval $interval): string
    {
        $key = $merchant->getKey();
        $identity = $merchant->getMorphClass().'|'.(is_scalar($key) ? (string) $key : '');

        return 'billing_'.substr(hash('sha256', $identity), 0, 16)
            .'_'.$tierKey.'_'.$amount->minorUnits.'_'.strtolower($amount->currency).'_'.$interval->value;
    }
}
