<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * How a hosted subscription on the separate-transfer lane names its merchant and its terms.
 *
 * ## Why metadata
 *
 * A destination subscription names its merchant in `transfer_data.destination` and its rate in
 * `application_fee_percent`, and the provider applies both to every invoice. A separate-transfer subscription
 * carries neither: the platform takes each cycle's whole payment and moves the merchant's share afterwards. The
 * merchant and the terms still have to travel with the subscription, because every later cycle is paid on a
 * webhook that knows nothing else, and `subscription_data.metadata` is what the provider copies onto the
 * subscription and reports with it.
 *
 * ## Why the terms are frozen at checkout
 *
 * The rate a buyer subscribed under is a fact about that subscription. Reading it from configuration at each
 * invoice would bill the twelfth cycle of an old subscription at a rate the platform set last week, and the
 * frozen fee on the row would then describe a term nobody agreed to.
 *
 * One class writes the keys and every reader asks it, so the checkout and the webhook cannot drift apart.
 */
final class StripeSubscriptionRouting
{
    /** Present only on the separate-transfer lane; a destination subscription names its account in `transfer_data`. */
    public const string ACCOUNT = 'billing_merchant_account';

    public const string FEE_BPS = 'billing_fee_bps';

    public const string FEE_FLAT_MINOR = 'billing_fee_flat_minor';

    public const string FEE_RESIDUAL = 'billing_fee_residual';

    /**
     * The metadata a separate-transfer subscription carries.
     *
     * @return array<string, string>
     */
    public static function metadata(MerchantAccountReference $account, PlatformFee $fee): array
    {
        return [
            self::ACCOUNT => $account->accountId,
            self::FEE_BPS => (string) $fee->bps,
            self::FEE_FLAT_MINOR => (string) $fee->flatMinor,
            self::FEE_RESIDUAL => $fee->residual->value,
        ];
    }

    /**
     * The merchant account a separate-transfer subscription names, or null for any other subscription.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    public static function accountOf(array $subscription): ?string
    {
        $account = self::metadataOf($subscription)[self::ACCOUNT] ?? null;

        return is_string($account) && $account !== '' ? $account : null;
    }

    /**
     * The terms a separate-transfer subscription was sold under, or null where it names none.
     *
     * A subscription that names an account and no readable terms answers null as well: its cycles cannot be priced,
     * and a guess at the rate would put a plausible wrong number on the row.
     *
     * @param  array<array-key, mixed>  $subscription
     */
    public static function termsOf(array $subscription): ?PlatformFee
    {
        $metadata = self::metadataOf($subscription);

        if (self::accountOf($subscription) === null) {
            return null;
        }

        $bps = $metadata[self::FEE_BPS] ?? null;
        $flat = $metadata[self::FEE_FLAT_MINOR] ?? null;
        $residual = RoundingResidual::tryFrom(is_string($metadata[self::FEE_RESIDUAL] ?? null) ? $metadata[self::FEE_RESIDUAL] : '');

        if (! is_string($bps) || ! ctype_digit($bps) || ! is_string($flat) || ! ctype_digit($flat) || ! $residual instanceof RoundingResidual) {
            return null;
        }

        return new PlatformFee((int) $bps, (int) $flat, $residual);
    }

    /**
     * @param  array<array-key, mixed>  $subscription
     * @return array<array-key, mixed>
     */
    private static function metadataOf(array $subscription): array
    {
        $metadata = $subscription['metadata'] ?? null;

        return is_array($metadata) ? $metadata : [];
    }
}
