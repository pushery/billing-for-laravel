<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SellerDataMeasure;
use Pushery\Billing\Models\SellerDataEscalationEpisode;

/**
 * Whether a merchant's share may move now, asked by every path that moves one.
 *
 * Two independent reasons hold a payout back, and neither lifts the other: a tax standing nobody has
 * established, and a measure over seller data that was not supplied. Both are asked here, so the three
 * paths that move a share (the payment itself, the release of a buyer-protection hold and the retry of a
 * failed transfer) cannot disagree about it.
 *
 * No withholding outlasts the money rail. Money that arrived longer ago than the rail allows moves whatever
 * held it: a hold past that limit is not a stricter measure but a payment nobody can complete. The seller
 * data measure may be set shorter than the rail, and then it ends there.
 *
 * Only where the package moves the share itself. On a destination charge the provider moves it with the
 * payment, so there is no moment at which anything here could hold it.
 */
final readonly class MerchantPayoutGate
{
    public const string TAX_STANDING = 'tax_standing';

    public const string SELLER_DATA = 'seller_data';

    public function __construct(
        private CreatorTaxStatusHold $taxStanding,
        private SellerDataEscalation $escalation,
    ) {}

    /** Why this merchant's money that arrived at `$arrivedAt` may not move now, or null where it may. */
    public function withheldBecause(Model $merchant, CarbonImmutable $arrivedAt, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $arrivedDaysAgo = max(0, (int) $arrivedAt->diffInDays($now));

        if ($this->escalation->payoutDeadlinePassed($arrivedDaysAgo)) {
            return null;
        }

        if ($this->taxStanding->blocksPayout($merchant, $now)) {
            return self::TAX_STANDING;
        }

        return ! $this->escalation->withholdingExhausted($arrivedDaysAgo) && $this->sellerDataWithholds($merchant)
            ? self::SELLER_DATA
            : null;
    }

    private function sellerDataWithholds(Model $merchant): bool
    {
        return SellerDataEscalationEpisode::model()::query()
            ->where('merchant_type', $merchant->getMorphClass())
            ->where('merchant_id', $merchant->getKey())
            ->whereNull('resolved_at')
            ->where('measure', SellerDataMeasure::WithholdPayout->value)
            ->exists();
    }
}
