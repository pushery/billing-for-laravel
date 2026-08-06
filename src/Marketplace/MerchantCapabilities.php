<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Support\Carbon;
use Pushery\Billing\Models\MerchantAccount;
use Pushery\Billing\ValueObjects\MerchantAccountReference;

/**
 * Applies what the provider has reported about a merchant's account to the local row.
 *
 * The capabilities are the provider's to grant and to withdraw, so this is the ONLY place that writes
 * them: a platform that could raise a flag itself could route money to a merchant nobody verified, and a
 * platform that could not lower one would keep routing to a merchant whose verification lapsed.
 *
 * Reports arrive asynchronously and out of order, and both directions are applied as given rather than
 * merged optimistically — a report that a capability is now false is exactly the report that matters, and
 * treating it as "probably stale" is how money keeps flowing to an account the provider has closed.
 *
 * Unknown accounts are IGNORED rather than created. A report about an account the package never issued
 * belongs to somebody else — another platform on the same provider, or an account created by hand — and
 * materializing a row for it would invent a merchant the application has no record of.
 */
final readonly class MerchantCapabilities
{
    public function apply(MerchantAccountReference $reported, ?Carbon $at = null): ?MerchantAccount
    {
        $account = MerchantAccount::query()
            ->where('provider', $reported->provider)
            ->where('account_reference', $reported->accountId)
            ->first();

        if (! $account instanceof MerchantAccount) {
            return null;
        }

        $account->forceFill([
            'charges_enabled' => $reported->chargesEnabled,
            'payouts_enabled' => $reported->payoutsEnabled,
            'details_submitted' => $reported->detailsSubmitted,
            // Stamped on every report, including one that changes nothing. An operator looking at a merchant
            // stuck on "cannot receive" needs to tell "we never heard" from "we heard, and were told no".
            'capabilities_refreshed_at' => $at ?? Carbon::now(),
        ])->save();

        return $account;
    }
}
