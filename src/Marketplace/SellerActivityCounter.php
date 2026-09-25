<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\TradingActivity;

/**
 * How much a seller has sold of goods on the platform's behalf as an intermediary, and which sale reached the
 * activity threshold.
 *
 * It reads the recorded sales rather than a running total, so the answer can be computed again for any
 * window at any time, and the sale that reached the threshold is named: that transaction is what justifies
 * asking the seller to declare, and a count that could not say which sale it was could not justify anything.
 *
 * What counts is narrow on purpose. Goods only, sold under intermediation, in the platform's own currency:
 * the question is whether a seller of goods has become a business, and a tip, a download or a subscription
 * says nothing about that. A sale whose payment failed is not a sale. Proceeds are what the buyers paid, and a
 * later refund does not move them, so the sale that reached the threshold stays the sale that reached it.
 * Rows written before the archetype was recorded carry none and are not counted.
 */
final readonly class SellerActivityCounter
{
    public function __construct(
        private SellerActivityThreshold $threshold,
        private Repository $config,
    ) {}

    public function activityAround(Model $seller, CarbonImmutable $now): TradingActivity
    {
        $currency = $this->config->get('billing.currency', 'EUR');
        $currency = is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR';
        [$from, $until] = $this->windowAround($now);

        $sales = MerchantCharge::model()::query()
            ->where('merchant_type', $seller->getMorphClass())
            ->where('merchant_id', $seller->getKey())
            ->where('tax_archetype', TaxArchetype::ConsumerGoods->value)
            ->where('seller_posture', SellerOfRecordPosture::PlatformIntermediary->value)
            ->where('settlement_state', '!=', SettlementState::Failed->value)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$from, $until])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $count = 0;
        $proceeds = 0;
        $reachedBy = null;
        $reachedAt = null;

        foreach ($sales as $sale) {
            $count++;
            $proceeds += $sale->gross_minor;

            if (! $reachedAt instanceof CarbonImmutable && $this->threshold->requiresStatusDeclaration($count, $proceeds)) {
                $reachedBy = $sale->charge_reference;
                $reachedAt = $sale->created_at !== null ? CarbonImmutable::instance($sale->created_at) : $now;
            }
        }

        return new TradingActivity($count, Money::of($proceeds, $currency), $reachedBy, $reachedAt);
    }

    /**
     * The window the two figures are counted over, both ends included.
     *
     * The calendar year by default, which is what the question is usually asked about; `rolling_year` counts
     * the 365 days up to now instead, for a platform that would rather not have the count start again every
     * January. Here rather than on the threshold, which answers only whether two figures reach it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function windowAround(CarbonImmutable $now): array
    {
        return $this->config->get('billing.marketplace.seller_activity.window', 'calendar_year') === 'rolling_year'
            ? [$now->subDays(365), $now]
            : [$now->startOfYear(), $now->endOfYear()];
    }
}
