<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\LedgerBalanceReader;
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
 * `held` is what buyer protection is still holding back — a settled charge whose payout is waiting on the
 * buyer, or on somebody deciding a dispute. It is subtracted from `available` rather than shown beside it,
 * because a merchant reading "available" is reading what they can be paid, and money a clock is still
 * sitting on is not that. Nothing here reaches a provider or writes a row.
 */
final readonly class MerchantChargeLedgerBalanceReader implements LedgerBalanceReader
{
    public function availableFor(Model $party, string $currency): Money
    {
        // Settled net, net of the merchant's own clawbacks — a refund or lost dispute reverses part of the
        // transfer, so the reversed sum is subtracted rather than counted as a second, separate balance.
        $settled = $this->base($party, $currency)->where('settlement_state', SettlementState::Settled->value);

        // …and less what is still held back. Leaving it in would tell a merchant they can be paid money that
        // a clock is still sitting on, which is the one thing an "available" figure must never do.
        $available = (int) $settled->sum('net_minor')
            - (int) $settled->sum('transfer_reversed_minor')
            - $this->heldFor($party, $currency)->minorUnits;

        return Money::of($available, $this->code($currency));
    }

    public function pendingFor(Model $party, string $currency): Money
    {
        $pending = $this->base($party, $currency)->where('settlement_state', SettlementState::Pending->value);

        return Money::of((int) $pending->sum('net_minor'), $this->code($currency));
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
        $open = array_values(array_map(
            static fn (BuyerProtectionState $state): string => $state->value,
            array_filter(BuyerProtectionState::cases(), static fn (BuyerProtectionState $state): bool => ! $state->settled()),
        ));

        $held = BuyerProtectionHold::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $this->key($party))
            ->where('currency', $this->code($currency))
            ->whereIn('state', $open)
            ->sum('charge_minor');

        return Money::of((int) $held, $this->code($currency));
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
        return MerchantCharge::query()
            ->where('merchant_type', $party->getMorphClass())
            ->where('merchant_id', $party->getKey())
            ->where('currency', $this->code($currency));
    }

    /** The ISO-4217 code as stored — uppercase, the form the routed charge wrote from its Money. */
    private function code(string $currency): string
    {
        return strtoupper($currency);
    }
}
