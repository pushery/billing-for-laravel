<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\MerchantOnboarding;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\ValueObjects\ClientIntent;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Stripe\StripeClient;

/**
 * Stripe's receiving side: create the merchant's connected account and hand back a link into Stripe's own
 * hosted identity flow.
 *
 * `createAccount` is idempotent through the LOCAL row, not through a provider lookup. Asking Stripe "does
 * this merchant already have an account" has no answer — Stripe has no notion of our merchants — so a
 * second call without the local check creates a second account, and a merchant with two accounts has their
 * money split across two identities the provider pays separately. The unique index behind the row is the
 * backstop for the concurrent case.
 *
 * The capability flags are stored false on creation regardless of what the API returns. A brand-new
 * account occasionally comes back with a capability already true, and trusting that would let money route
 * to a merchant before the verification that the flags are supposed to represent has actually happened.
 * They are raised only by the provider's own account event.
 */
final readonly class StripeMerchantOnboarding implements MerchantOnboarding
{
    /**
     * The account types Stripe offers, and the ones this driver supports. The choice moves KYC duty and
     * loss liability between Stripe and the platform, and it cannot be changed for an account that has
     * already onboarded — so it is boot-time configuration with a loud failure, never a per-call argument.
     *
     * @var list<string>
     */
    public const array ACCOUNT_TYPES = ['express', 'standard'];

    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
    ) {}

    public function createAccount(Model $merchant): MerchantAccountReference
    {
        $existing = $this->row($merchant);

        if ($existing instanceof MerchantAccount) {
            return $existing->toReference();
        }

        $key = $merchant->getKey();

        $account = $this->stripe->accounts->create([
            'type' => $this->accountType(),
            'metadata' => [
                'billing_merchant_type' => $merchant->getMorphClass(),
                'billing_merchant_id' => is_scalar($key) ? (string) $key : '',
            ],
        ]);

        $row = MerchantAccount::query()->create([
            'merchant_type' => $merchant->getMorphClass(),
            'merchant_id' => $key,
            'provider' => 'stripe',
            'account_reference' => (string) $account->id,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ]);

        return $row->toReference();
    }

    public function onboardingLink(Model $merchant, string $refreshUrl, string $returnUrl): ClientIntent
    {
        $account = $this->createAccount($merchant);

        $link = $this->stripe->accountLinks->create([
            'account' => $account->accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return new ClientIntent('stripe', [
            'url' => (string) $link->url,
            'account' => $account->accountId,
            // Stripe's links are single-use and short-lived. Handing the expiry across the boundary lets a
            // consumer show "this link has expired, start again" instead of a provider error page.
            'expires_at' => Carbon::createFromTimestampUTC((int) $link->expires_at)->toIso8601String(),
        ]);
    }

    private function row(Model $merchant): ?MerchantAccount
    {
        return MerchantAccount::query()
            ->where('provider', 'stripe')
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->first();
    }

    /** The configured account type, refused loudly when it is one this driver does not support. */
    private function accountType(): string
    {
        $type = $this->config->get('billing.marketplace.onboarding.account_type', 'express');

        if (! is_string($type) || ! in_array($type, self::ACCOUNT_TYPES, true)) {
            throw InvalidBillingConfig::unsupportedMerchantAccountType(
                is_string($type) ? $type : gettype($type),
                self::ACCOUNT_TYPES,
            );
        }

        return $type;
    }
}
