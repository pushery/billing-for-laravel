<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Contracts\LedgerBalanceReader;
use Pushery\Billing\Contracts\ListsEarningCurrencies;
use Pushery\Billing\Enums\BuyerProtectionState;
use Pushery\Billing\Enums\SettlementState;
use Pushery\Billing\Models\BuyerProtectionHold;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\ValueObjects\Money;

/**
 * The shipped balance reader: a pure aggregate projection over the routed-charge record.
 *
 * It keeps no state of its own — every answer is a SUM over `billing_merchant_charges` for the party, so the
 * balance can never drift from the charges it is made of. `available` is settled net LESS what has been
 * clawed back (a refund or a lost dispute reverses the merchant's share), so a reversal is netted out rather
 * than double-counted. A failed charge is neither settled nor pending and contributes nothing.
 *
 * `held` is what buyer protection is still holding back: the share of a sale whose payout is waiting on the
 * buyer, or on somebody deciding a dispute. A protected sale stays pending until its hold releases, because the
 * release is what moves the share and settles the sale. What a hold sits on is taken out of the bucket its sale
 * is in, `pending` in that flow and `available` for a hold over a sale that settled anyway, so no money is
 * counted twice and a merchant reading "available" is never told they can be paid money a clock is still
 * sitting on. Nothing here reaches a provider or writes a row.
 */
final readonly class MerchantChargeLedgerBalanceReader implements LedgerBalanceReader, ListsEarningCurrencies
{
    public function availableFor(Model $party, string $currency): Money
    {
        // Settled net, net of the merchant's own clawbacks — a refund or lost dispute reverses part of the
        // transfer, so the reversed sum is subtracted rather than counted as a second, separate balance.
        $settled = $this->base($party, $currency)->where('settlement_state', SettlementState::Settled->value);

        // …and less what is still held back over a settled sale. Leaving it in would tell a merchant they can be
        // paid money that a clock is still sitting on, which is the one thing an "available" figure must never do.
        $available = (int) $settled->sum('net_minor')
            - (int) $settled->sum('transfer_reversed_minor')
            - $this->heldOver($party, $currency, SettlementState::Settled);

        return Money::of($available, $this->code($currency));
    }

    public function pendingFor(Model $party, string $currency): Money
    {
        $pending = $this->base($party, $currency)->where('settlement_state', SettlementState::Pending->value);

        // Less what a hold sits on. A protected sale is pending until its hold releases, and the hold reports its
        // share as held: counted here as well, the same money would stand in two buckets.
        $net = (int) $pending->sum('net_minor') - $this->heldOver($party, $currency, SettlementState::Pending);

        return Money::of($net, $this->code($currency));
    }

    /**
     * What buyer protection is still holding back.
     *
     * Only holds whose outcome is genuinely open count. A released one is money that has gone, a refunded one
     * is money that came back, and both are already reflected in the charge record — counting them here as
     * well would subtract them twice.
     */
    public function heldFor(Model $party, string $currency): Money
    {
        // The MERCHANT's share, not the price the buyer paid. `availableFor()` and `pendingFor()` subtract this
        // from `net_minor`, which is already net of the platform's commission — so subtracting the gross would
        // take the commission out a second time and, where the whole turnover sits under one open hold, drive the
        // balance below zero. The contract says the quantity out loud: "earnings withheld under buyer protection".
        $held = $this->openHolds($party, $currency)->sum(DB::raw('charge_minor - platform_fee_minor'));

        return Money::of((int) $held, $this->code($currency));
    }

    /** What the open holds sit on over this party's sales in one settlement state, the bucket those sales are in. */
    private function heldOver(Model $party, string $currency, SettlementState $state): int
    {
        $sales = $this->base($party, $currency)->where('settlement_state', $state->value)->select('charge_reference');

        return (int) $this->openHolds($party, $currency)
            ->whereIn('charge_reference', $sales)
            ->sum(DB::raw('charge_minor - platform_fee_minor'));
    }

    /**
     * The party's holds in one currency whose outcome is still open.
     *
     * @return Builder<BuyerProtectionHold>
     */
    private function openHolds(Model $party, string $currency): Builder
    {
        $open = array_values(array_map(
            static fn (BuyerProtectionState $state): string => $state->value,
            array_filter(BuyerProtectionState::cases(), static fn (BuyerProtectionState $state): bool => ! $state->settled()),
        ));

        return BuyerProtectionHold::model()::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $this->key($party))
            ->where('currency', $this->code($currency))
            ->whereIn('state', $open);
    }

    /** A party's key as the hold table stores it — a string column, so the comparison has to be one. */
    private function key(Model $party): string
    {
        $key = $party->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * The party's charges in one currency.
     *
     * @return Builder<MerchantCharge>
     */
    private function base(Model $party, string $currency): Builder
    {
        return MerchantCharge::model()::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $party->getKey())
            ->where('currency', $this->code($currency));
    }

    /** The ISO-4217 code as stored — uppercase, the form the routed charge wrote from its Money. */
    private function code(string $currency): string
    {
        return strtoupper($currency);
    }

    /**
     * Every currency this party has earned in — the enumeration the per-currency readers needed and lacked.
     *
     * A failed charge contributes nothing here for the same reason it contributes nothing to a balance: it
     * is neither settled nor pending, and a currency that only ever appeared on a failed charge is a currency
     * nobody was paid in. Listing it would send a caller to fetch a balance that is structurally zero.
     *
     * Sorted and uppercase so two calls read identically and a caller can compare lists without normalizing.
     *
     * @return list<string>
     */
    public function currenciesFor(Model $party): array
    {
        $currencies = MerchantCharge::model()::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $party->getKey())
            ->where('settlement_state', '!=', SettlementState::Failed->value)
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->all();

        return array_values(array_map($this->code(...), array_filter($currencies, is_string(...))));
    }
}
