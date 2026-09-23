<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\Dispute;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\DisputeRate;

/**
 * The dispute rate of the routed sales, per merchant and across the platform, over a window.
 *
 * The card networks measure a seller by the share of its payments that are disputed, and act on it: past a
 * threshold a seller pays for every case and can lose card acceptance. The package is the one place that knows
 * both the sales and the disputes, so the figure is read here rather than rebuilt by every host with a
 * denominator of its own.
 *
 * ## What is counted
 *
 * The NUMERATOR is disputes OPENED in the window, as `RecordOpenedDispute` kept them, whatever their outcome. A
 * network counts a case when it is raised, and one the seller later wins still counted.
 *
 * The DENOMINATOR is the number of PAYMENTS that arrived in the window: routed charges that settled, dated by
 * when they settled. Not turnover: one disputed payment of a hundred is the same rate whether the payments were
 * small or large, and a denominator in money would move with prices. A pending or failed charge is not a payment
 * that arrived and is left out. Each subscription cycle is a payment of its own.
 *
 * Both sides are the ROUTED sales, the ones a merchant made. A platform that also sells on its own account has
 * payments this does not see, so for that account the figure is an upper bound on what the network measures.
 */
final readonly class DisputeRates
{
    /** The rate of one merchant's sales. */
    public function forMerchant(Model $merchant, CarbonImmutable $from, CarbonImmutable $to): DisputeRate
    {
        $this->assertWindow($from, $to);

        return new DisputeRate(
            disputes: $this->disputes($from, $to)
                ->where('merchant_type', $merchant->getMorphClass())
                ->where('merchant_id', $merchant->getKey())
                ->count(),
            payments: $this->payments($from, $to)
                ->where('merchant_type', $merchant->getMorphClass())
                ->where('merchant_id', $merchant->getKey())
                ->count(),
            from: $from,
            to: $to,
        );
    }

    /**
     * The rate across every merchant's sales.
     *
     * A merchant erased since keeps counting: the row is unlinked, not deleted, and the payments it arose over
     * still happened.
     */
    public function forPlatform(CarbonImmutable $from, CarbonImmutable $to): DisputeRate
    {
        $this->assertWindow($from, $to);

        return new DisputeRate(
            disputes: $this->disputes($from, $to)
                ->where(static fn (Builder $routed): Builder => $routed
                    ->whereNotNull('merchant_type')
                    ->orWhereNotNull('merchant_erased_at'))
                ->count(),
            payments: $this->payments($from, $to)->count(),
            from: $from,
            to: $to,
        );
    }

    /** @return Builder<Dispute> */
    private function disputes(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Dispute::model()::query()
            ->where('opened_at', '>=', $from)
            ->where('opened_at', '<', $to);
    }

    /** @return Builder<MerchantCharge> */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return MerchantCharge::model()::query()
            ->where('settlement_state', SettlementState::Settled)
            ->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $to);
    }

    private function assertWindow(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to <= $from) {
            throw new InvalidArgumentException("A dispute rate needs a window that ends after it starts; {$from->toIso8601String()} to {$to->toIso8601String()} is empty.");
        }
    }
}
