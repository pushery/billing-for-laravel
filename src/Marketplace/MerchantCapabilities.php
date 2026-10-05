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
 * treating it as "probably stale" is how money keeps flowing to an account the provider has closed. What is
 * set aside is a report OLDER than the one the row last took, by the provider's own stamp: retried after a
 * failure, an earlier "can be paid" would otherwise undo a later "cannot". Two reports stamped the same
 * second have no order the provider tells, so the second may lower a flag but never raise one. A report
 * without a stamp is applied as given.
 *
 * Unknown accounts are IGNORED rather than created. A report about an account the package never issued
 * belongs to somebody else — another platform on the same provider, or an account created by hand — and
 * materializing a row for it would invent a merchant the application has no record of.
 */
final readonly class MerchantCapabilities
{
    /**
     * @param  ?int  $occurredAt  the provider event's own timestamp, in Unix seconds, where it gives one
     */
    public function apply(MerchantAccountReference $reported, ?Carbon $at = null, ?int $occurredAt = null): ?MerchantAccount
    {
        $model = MerchantAccount::model();

        // Read and written under the row's lock, so two reports handled at once are compared one after the
        // other rather than both against the row as it was before either.
        return new $model()->getConnection()->transaction(static function () use ($model, $reported, $at, $occurredAt): ?MerchantAccount {
            $account = $model::query()
                ->where('provider', $reported->provider)
                ->where('account_reference', $reported->accountId)
                ->lockForUpdate()
                ->first();

            if (! $account instanceof MerchantAccount) {
                return null;
            }

            $taken = $account->capabilities_event_at;

            // Older than the report the flags were taken from: it describes a state the account has left.
            if ($occurredAt !== null && $taken !== null && $occurredAt < $taken) {
                return $account;
            }

            $flags = [
                'charges_enabled' => $reported->chargesEnabled,
                'payouts_enabled' => $reported->payoutsEnabled,
                'details_submitted' => $reported->detailsSubmitted,
            ];

            // The same second as the report the flags were taken from, with no order between the two: a flag may
            // go down on it, and none goes up.
            if ($occurredAt !== null && $occurredAt === $taken) {
                foreach ($flags as $flag => $value) {
                    $flags[$flag] = $value && $account->getAttribute($flag) === true;
                }
            }

            $account->forceFill([
                ...$flags,
                // Stamped on every report taken, including one that changes nothing. An operator looking at a
                // merchant stuck on "cannot receive" needs to tell "we never heard" from "we heard, and were told
                // no".
                'capabilities_refreshed_at' => $at ?? Carbon::now(),
                'capabilities_event_at' => $occurredAt ?? $taken,
            ])->save();

            return $account;
        });
    }
}
