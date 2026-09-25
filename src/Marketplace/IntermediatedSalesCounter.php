<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Money;

/**
 * What a seller was paid for the sales the platform arranged as an intermediary, read off the sales themselves.
 *
 * Under intermediation no settlement document exists. The platform charges the seller a fee for arranging the
 * sale and the seller documents the sale themselves, so the only record of it here is the charge row. This
 * counts from that row what the reporting record asks: how many sales there were, what reached the seller
 * (the price less the platform's fee, less anything taken back from them), and the fee itself, which under
 * intermediation is charged to the seller.
 *
 * A sale whose payment failed is not a sale. A row written before its archetype was recorded is counted all
 * the same, as an unclassified line: leaving it out would understate the seller, and guessing its kind would
 * classify it on a guess. A row written before its posture was recorded counts under the installation's
 * posture, as the column documents.
 */
final readonly class IntermediatedSalesCounter
{
    public function __construct(private MarketplaceSaleContext $sale) {}

    /**
     * The sellers with intermediated sales in the window, as their stored morph pairs.
     *
     * @return list<array{type: string, id: string}>
     */
    public function sellersIn(CountingPeriod $period, string $currency): array
    {
        $pairs = [];

        $rows = $this->salesIn($currency, $period)
            ->whereNotNull('merchant_type')
            ->whereNotNull('merchant_id')
            ->distinct()
            ->get(['merchant_type', 'merchant_id']);

        foreach ($rows as $row) {
            $pairs[] = ['type' => (string) $row->merchant_type, 'id' => (string) $row->merchant_id];
        }

        return $pairs;
    }

    /** What reached the seller: each sale's share, less what was taken back from them. */
    public function countedIn(Model $seller, string $currency, CountingPeriod $period): Money
    {
        $minor = 0;

        foreach ($this->of($seller, $currency, $period)->get(['net_minor', 'transfer_reversed_minor']) as $sale) {
            $minor += $sale->net_minor - $sale->transfer_reversed_minor;
        }

        return Money::of($minor, strtoupper($currency));
    }

    public function transactionsIn(Model $seller, string $currency, CountingPeriod $period): int
    {
        return $this->of($seller, $currency, $period)->count();
    }

    /** What the platform charged the seller for arranging the sales, less what it refunded of that. */
    public function feesIn(Model $seller, string $currency, CountingPeriod $period): Money
    {
        $minor = 0;

        foreach ($this->of($seller, $currency, $period)->get(['fee_minor', 'fee_refunded_minor']) as $sale) {
            $minor += $sale->fee_minor - $sale->fee_refunded_minor;
        }

        return Money::of($minor, strtoupper($currency));
    }

    /**
     * The same figures per kind of thing sold, in the shape the settlement counter gives them.
     *
     * A sale records its archetype but not what a tip was paid on, so a tip here carries no reference and
     * reads as unclassified downstream. That is the honest answer: the rule for a tip is the rule of what it
     * accompanied, and nothing here says what that was.
     *
     * @return array<string, array{archetype: ?TaxArchetype, soldAlongside: ?TaxArchetype, gross: Money, transactions: int}>
     */
    public function countedInByArchetype(Model $seller, string $currency, CountingPeriod $period): array
    {
        $currency = strtoupper($currency);
        $groups = [];

        foreach ($this->of($seller, $currency, $period)->get(['tax_archetype', 'net_minor', 'transfer_reversed_minor']) as $sale) {
            $archetype = $sale->tax_archetype;
            $key = $archetype instanceof TaxArchetype ? $archetype->value : 'unclassified';

            $groups[$key] ??= ['archetype' => $archetype, 'soldAlongside' => null, 'gross' => Money::of(0, $currency), 'transactions' => 0];
            $groups[$key]['gross'] = $groups[$key]['gross']->plus(Money::of($sale->net_minor - $sale->transfer_reversed_minor, $currency));
            $groups[$key]['transactions']++;
        }

        return $groups;
    }

    /**
     * Whether this sale was arranged as an intermediary, so that no settlement document will ever claim it.
     *
     * The same test the queries here apply, for a row already loaded: the recorded posture, or the
     * installation's where the row never recorded one.
     */
    public function arranged(MerchantCharge $sale): bool
    {
        return ($sale->seller_posture ?? $this->sale->posture()) === SellerOfRecordPosture::PlatformIntermediary;
    }

    /** @return Builder<MerchantCharge> */
    private function of(Model $seller, string $currency, CountingPeriod $period): Builder
    {
        return $this->salesIn($currency, $period)
            ->where('merchant_type', $seller->getMorphClass())
            ->where('merchant_id', $seller->getKey());
    }

    /** @return Builder<MerchantCharge> */
    private function salesIn(string $currency, CountingPeriod $period): Builder
    {
        $unrecordedCounts = $this->sale->posture() === SellerOfRecordPosture::PlatformIntermediary;

        return MerchantCharge::model()::query()
            ->where(static function (Builder $query) use ($unrecordedCounts): void {
                $query->where('seller_posture', SellerOfRecordPosture::PlatformIntermediary->value);

                if ($unrecordedCounts) {
                    $query->orWhereNull('seller_posture');
                }
            })
            ->where('settlement_state', '!=', SettlementState::Failed->value)
            ->where('currency', strtoupper($currency))
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<', $period->until);
    }
}
