<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ArrearsClock;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The clock as this package keeps it: one column on the owner's subscription row for that merchant.
 *
 * This is the whole of what the suspension ladder used to do inline, moved out so the ladder can be pointed
 * at somebody else's storage without being rewritten. The reading itself is unchanged, and the paragraph
 * below is the reason it looks the way it does rather than the obvious way.
 *
 * ## ONE row's clock — this merchant's — and no ordering, because there is nothing to order
 *
 * The two earlier versions both read ACROSS rows. First the newest, which let a fresh row with no clock
 * reset the ladder outright: a fan two rungs deep walked back to zero by subscribing to anybody. Then the
 * earliest, which fixed the reset by making the longest-standing debt govern every merchant at once.
 *
 * Ordering was never the question. Any aggregate over rows makes a debt owed to one creator decide another
 * creator's surfaces, and the scope is what removes it. `forMerchant()` is the single place the
 * (billable, merchant) selection is spelled, and a null scope collapses to the platform sentinel there — so
 * a single-seller install reads exactly the row it always read.
 */
final readonly class LocalArrearsClock implements ArrearsClock
{
    public function delinquentSince(Model $owner, ?MerchantScope $merchant = null): ?DateTimeInterface
    {
        $since = Subscription::query()
            ->forOwner($owner)
            ->ofDefaultType()
            ->forMerchant($merchant)
            ->whereNotNull('delinquent_since')
            ->value('delinquent_since');

        return $since instanceof DateTimeInterface ? $since : null;
    }
}
